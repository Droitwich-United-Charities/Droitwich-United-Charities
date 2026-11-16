// DUC Website server — Droitwich United Charities
// Password-protected site, Global Admin user management, maintenance mode and audit log.
// Built by Saltway Digital.
package main

import (
	"bufio"
	"bytes"
	"crypto/pbkdf2"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"embed"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"html"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

//go:embed web/*
var webFS embed.FS

const appVersion = "1.0.0"
const pbkdfIter = 210000

// ---------- data types ----------

type User struct {
	ID         string    `json:"id"`
	Username   string    `json:"username"`
	Name       string    `json:"name"`
	Role       string    `json:"role"` // admin | editor | viewer
	Salt       string    `json:"salt"`
	Hash       string    `json:"hash"`
	Iter       int       `json:"iter"`
	Disabled   bool      `json:"disabled"`
	MustChange bool      `json:"mustChange"`
	Created    time.Time `json:"created"`
	LastLogin  time.Time `json:"lastLogin"`
}

type PublicUser struct {
	ID         string    `json:"id"`
	Username   string    `json:"username"`
	Name       string    `json:"name"`
	Role       string    `json:"role"`
	Disabled   bool      `json:"disabled"`
	MustChange bool      `json:"mustChange"`
	Created    time.Time `json:"created"`
	LastLogin  time.Time `json:"lastLogin"`
}

func (u *User) Public() PublicUser {
	return PublicUser{u.ID, u.Username, u.Name, u.Role, u.Disabled, u.MustChange, u.Created, u.LastLogin}
}

type Settings struct {
	RequireLogin bool   `json:"requireLogin"`
	Maintenance  bool   `json:"maintenance"`
	MaintTitle   string `json:"maintTitle"`
	MaintMessage string `json:"maintMessage"`
	MaintUntil   string `json:"maintUntil"`
	SessionHours int    `json:"sessionHours"`
}

type Enquiry struct {
	ID       string    `json:"id"`
	Date     time.Time `json:"date"`
	Name     string    `json:"name"`
	Contact  string    `json:"contact"`
	Type     string    `json:"type"`
	Postcode string    `json:"postcode"`
	Purpose  string    `json:"purpose"`
	Amount   string    `json:"amount"`
	Status   string    `json:"status"`
	By       string    `json:"by"`
}

type AuditEntry struct {
	Time    time.Time `json:"time"`
	User    string    `json:"user"`
	IP      string    `json:"ip"`
	Action  string    `json:"action"`
	Details string    `json:"details"`
}

type Session struct {
	UserID  string
	Expires time.Time
	Created time.Time
}

type Config struct {
	Listen string `json:"listen"`
}

// ---------- store ----------

type Store struct {
	mu        sync.Mutex
	dir       string
	users     []*User
	settings  Settings
	content   json.RawMessage
	enquiries []*Enquiry
	sessions  map[string]*Session
	fails     map[string]*failInfo
	auditMu   sync.Mutex
}

type failInfo struct {
	Count int
	Until time.Time
	Last  time.Time
}

func (s *Store) path(n string) string { return filepath.Join(s.dir, n) }

func readJSON(p string, v any) error {
	b, err := os.ReadFile(p)
	if err != nil {
		return err
	}
	return json.Unmarshal(b, v)
}

func writeJSON(p string, v any) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, b, 0600); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}

func (s *Store) load() error {
	if err := os.MkdirAll(s.dir, 0700); err != nil {
		return err
	}
	s.sessions = map[string]*Session{}
	s.fails = map[string]*failInfo{}
	s.settings = Settings{RequireLogin: true, MaintTitle: "We'll be back soon", MaintMessage: "Our website is currently undergoing scheduled maintenance. Thank you for your patience.", SessionHours: 12}
	_ = readJSON(s.path("settings.json"), &s.settings)
	if s.settings.SessionHours <= 0 {
		s.settings.SessionHours = 12
	}
	_ = readJSON(s.path("users.json"), &s.users)
	_ = readJSON(s.path("enquiries.json"), &s.enquiries)
	if b, err := os.ReadFile(s.path("content.json")); err == nil && json.Valid(b) {
		s.content = b
	}
	return nil
}

func (s *Store) saveUsers() error     { return writeJSON(s.path("users.json"), s.users) }
func (s *Store) saveSettings() error  { return writeJSON(s.path("settings.json"), s.settings) }
func (s *Store) saveEnquiries() error { return writeJSON(s.path("enquiries.json"), s.enquiries) }

func (s *Store) audit(user, ip, action, details string) {
	s.auditMu.Lock()
	defer s.auditMu.Unlock()
	e := AuditEntry{time.Now().UTC(), user, ip, action, details}
	b, _ := json.Marshal(e)
	f, err := os.OpenFile(s.path("audit.log"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil {
		log.Println("audit write failed:", err)
		return
	}
	defer f.Close()
	f.Write(append(b, '\n'))
	log.Printf("[audit] %s %s %s %s", user, ip, action, details)
}

func (s *Store) readAudit() []AuditEntry {
	s.auditMu.Lock()
	defer s.auditMu.Unlock()
	var out []AuditEntry
	f, err := os.Open(s.path("audit.log"))
	if err != nil {
		return out
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1024*1024), 4*1024*1024)
	for sc.Scan() {
		var e AuditEntry
		if json.Unmarshal(sc.Bytes(), &e) == nil {
			out = append(out, e)
		}
	}
	return out
}

func (s *Store) userByID(id string) *User {
	for _, u := range s.users {
		if u.ID == id {
			return u
		}
	}
	return nil
}

func (s *Store) userByName(n string) *User {
	n = strings.ToLower(strings.TrimSpace(n))
	for _, u := range s.users {
		if u.Username == n {
			return u
		}
	}
	return nil
}

func (s *Store) activeAdmins() int {
	c := 0
	for _, u := range s.users {
		if u.Role == "admin" && !u.Disabled {
			c++
		}
	}
	return c
}

func (s *Store) revokeSessions(userID string) {
	for k, v := range s.sessions {
		if v.UserID == userID {
			delete(s.sessions, k)
		}
	}
}

// ---------- crypto helpers ----------

func randHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func hashPassword(pw, saltHex string, iter int) string {
	salt, _ := hex.DecodeString(saltHex)
	k, err := pbkdf2.Key(sha256.New, pw, salt, iter, 32)
	if err != nil {
		panic(err)
	}
	return hex.EncodeToString(k)
}

func setPassword(u *User, pw string) {
	u.Salt = randHex(16)
	u.Iter = pbkdfIter
	u.Hash = hashPassword(pw, u.Salt, u.Iter)
}

func checkPassword(u *User, pw string) bool {
	h := hashPassword(pw, u.Salt, u.Iter)
	return subtle.ConstantTimeCompare([]byte(h), []byte(u.Hash)) == 1
}

var dummyUser = func() *User { u := &User{}; setPassword(u, randHex(8)); return u }()

var usernameRe = regexp.MustCompile(`^[a-z0-9._@-]{3,64}$`)

func validPassword(pw string) error {
	if len([]rune(pw)) < 10 {
		return errors.New("Password must be at least 10 characters")
	}
	if len(pw) > 200 {
		return errors.New("Password is too long")
	}
	return nil
}

func validRole(r string) bool { return r == "admin" || r == "editor" || r == "viewer" }

func roleLabel(r string) string {
	switch r {
	case "admin":
		return "Global Admin"
	case "editor":
		return "Editor"
	}
	return "Viewer"
}

// ---------- server ----------

type Server struct {
	st *Store
}

func clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	ip := net.ParseIP(host)
	// Trust X-Forwarded-For only from a local reverse proxy (Caddy)
	if ip != nil && ip.IsLoopback() {
		if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
			parts := strings.Split(xff, ",")
			return strings.TrimSpace(parts[len(parts)-1])
		}
	}
	return host
}

func isHTTPS(r *http.Request) bool {
	if r.TLS != nil {
		return true
	}
	host, _, _ := net.SplitHostPort(r.RemoteAddr)
	if ip := net.ParseIP(host); ip != nil && ip.IsLoopback() {
		return strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https")
	}
	return false
}

const cookieName = "duc_session"

func (sv *Server) currentUser(r *http.Request) (*User, string) {
	c, err := r.Cookie(cookieName)
	if err != nil || c.Value == "" {
		return nil, ""
	}
	st := sv.st
	st.mu.Lock()
	defer st.mu.Unlock()
	ses, ok := st.sessions[c.Value]
	if !ok || time.Now().After(ses.Expires) {
		delete(st.sessions, c.Value)
		return nil, ""
	}
	u := st.userByID(ses.UserID)
	if u == nil || u.Disabled {
		delete(st.sessions, c.Value)
		return nil, ""
	}
	ses.Expires = time.Now().Add(time.Duration(st.settings.SessionHours) * time.Hour)
	cp := *u
	return &cp, c.Value
}

func (sv *Server) startSession(w http.ResponseWriter, r *http.Request, u *User) {
	tok := randHex(32)
	sv.st.mu.Lock()
	hrs := sv.st.settings.SessionHours
	sv.st.sessions[tok] = &Session{UserID: u.ID, Created: time.Now(), Expires: time.Now().Add(time.Duration(hrs) * time.Hour)}
	sv.st.mu.Unlock()
	http.SetCookie(w, &http.Cookie{Name: cookieName, Value: tok, Path: "/", HttpOnly: true, Secure: isHTTPS(r), SameSite: http.SameSiteLaxMode, MaxAge: hrs * 3600})
}

func clearCookie(w http.ResponseWriter, r *http.Request) {
	http.SetCookie(w, &http.Cookie{Name: cookieName, Value: "", Path: "/", HttpOnly: true, Secure: isHTTPS(r), SameSite: http.SameSiteLaxMode, MaxAge: -1})
}

func secHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Frame-Options", "DENY")
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("Referrer-Policy", "same-origin")
		h.Set("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
		h.Set("Content-Security-Policy", "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
		if isHTTPS(r) {
			h.Set("Strict-Transport-Security", "max-age=31536000")
		}
		next.ServeHTTP(w, r)
	})
}

// ---------- server-rendered pages ----------

const pageCSS = `
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font-family:"Source Sans 3",system-ui,sans-serif;color:#1d2433;background:linear-gradient(180deg,#eaf2fb 0%,#f7f5ef 100%)}
.card{background:#fff;width:100%;max-width:420px;border-radius:18px;padding:36px 32px;box-shadow:0 18px 40px rgba(20,37,74,.12);text-align:center}
.card.wide{max-width:560px}
img.logo{width:132px;height:132px;object-fit:contain;margin-bottom:8px}
h1{font-family:"Libre Baskerville",Georgia,serif;color:#14254a;font-size:1.5rem;margin:6px 0 8px}
p{color:#5b6475;line-height:1.55;margin:0 0 14px}
form{text-align:left;margin-top:18px}
label{display:block;font-weight:600;color:#14254a;font-size:14px;margin:12px 0 5px}
input{width:100%;padding:11px 13px;border:1px solid #cfd3dc;border-radius:10px;font:inherit;font-size:16px}
input:focus{outline:2px solid #7fa8d6;border-color:#7fa8d6}
button{width:100%;margin-top:20px;background:#2f6b3a;color:#fff;border:0;border-radius:999px;padding:13px;font:inherit;font-weight:700;font-size:16px;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{background:#fbe9e7;color:#b3261e;border-radius:10px;padding:10px 12px;font-size:14px;margin-top:14px;text-align:left}
.ok{background:#e6efe7;color:#2f6b3a;border-radius:10px;padding:10px 12px;font-size:14px;margin-top:14px;text-align:left}
.small{font-size:13px;color:#7a8293;margin-top:22px}
a{color:#2f6b3a}
.until{display:inline-block;background:#e8eef8;color:#14254a;border-radius:999px;padding:6px 14px;font-weight:600;font-size:14px;margin:4px 0 14px}
.bar{height:6px;border-radius:6px;background:linear-gradient(90deg,#14254a,#2f6b3a,#7fa8d6);margin:18px 0 0}
`

func renderPage(w http.ResponseWriter, status int, title, body string, wide bool) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	cls := "card"
	if wide {
		cls += " wide"
	}
	fmt.Fprintf(w, `<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%s – Droitwich United Charities</title><link rel="icon" href="/assets/duc-logo.webp"><link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet"><style>%s</style></head><body><main class="%s"><img class="logo" src="/assets/duc-logo.webp" alt="Droitwich United Charities">%s</main></body></html>`,
		html.EscapeString(title), pageCSS, cls, body)
}

func esc(s string) string { return html.EscapeString(s) }

func safeNext(n string) string {
	if n == "" || !strings.HasPrefix(n, "/") || strings.HasPrefix(n, "//") || strings.Contains(n, "\\") {
		return "/"
	}
	return n
}

func (sv *Server) loginPage(w http.ResponseWriter, r *http.Request, msg, next string, status int) {
	body := `<h1>Members sign in</h1><p>This website is private. Please sign in with the account your administrator gave you.</p>`
	if msg != "" {
		body += `<div class="err">` + esc(msg) + `</div>`
	}
	if r.URL.Query().Get("out") == "1" {
		body += `<div class="ok">You have been signed out.</div>`
	}
	body += `<form method="post" action="/login"><input type="hidden" name="next" value="` + esc(safeNext(next)) + `">
<label for="u">Username</label><input id="u" name="username" autocomplete="username" required autofocus>
<label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required>
<button type="submit">Sign in</button></form>
<p class="small">Forgotten your password? Ask your Global Admin to reset it.</p>`
	renderPage(w, status, "Sign in", body, false)
}

func (sv *Server) maintenancePage(w http.ResponseWriter, u *User) {
	sv.st.mu.Lock()
	s := sv.st.settings
	sv.st.mu.Unlock()
	body := `<h1>` + esc(s.MaintTitle) + `</h1><p>` + strings.ReplaceAll(esc(s.MaintMessage), "\n", "<br>") + `</p>`
	if s.MaintUntil != "" {
		body += `<span class="until">Expected back: ` + esc(s.MaintUntil) + `</span>`
	}
	body += `<p>Droitwich United Charities · Registered charity 205536<br>01905 775226</p><div class="bar"></div>`
	if u != nil {
		body += `<p class="small">Signed in as ` + esc(u.Name) + ` · <a href="/logout">Sign out</a></p>`
	} else {
		body += `<p class="small"><a href="/login">Staff sign in</a></p>`
	}
	w.Header().Set("Retry-After", "3600")
	renderPage(w, http.StatusServiceUnavailable, "Under maintenance", body, true)
}

// ---------- page handlers ----------

func (sv *Server) hasUsers() bool {
	sv.st.mu.Lock()
	defer sv.st.mu.Unlock()
	return len(sv.st.users) > 0
}

func (sv *Server) handleRoot(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" && r.URL.Path != "/index.html" {
		http.NotFound(w, r)
		return
	}
	if !sv.hasUsers() {
		http.Redirect(w, r, "/setup", http.StatusSeeOther)
		return
	}
	u, _ := sv.currentUser(r)
	if u != nil && u.MustChange {
		http.Redirect(w, r, "/change-password", http.StatusSeeOther)
		return
	}
	sv.st.mu.Lock()
	set := sv.st.settings
	sv.st.mu.Unlock()
	staff := u != nil && (u.Role == "admin" || u.Role == "editor")
	if set.Maintenance && !staff {
		sv.maintenancePage(w, u)
		return
	}
	if set.RequireLogin && u == nil {
		http.Redirect(w, r, "/login", http.StatusSeeOther)
		return
	}
	b, _ := webFS.ReadFile("web/site.html")
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Write(b)
}

func (sv *Server) handleAsset(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimPrefix(r.URL.Path, "/assets/")
	if name != "duc-logo.webp" {
		http.NotFound(w, r)
		return
	}
	b, _ := webFS.ReadFile("web/" + name)
	w.Header().Set("Content-Type", "image/webp")
	w.Header().Set("Cache-Control", "public, max-age=86400")
	w.Write(b)
}

func (sv *Server) handleSetup(w http.ResponseWriter, r *http.Request) {
	if sv.hasUsers() {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	form := func(msg string) {
		body := `<h1>Welcome – first-time setup</h1><p>Create the <b>Global Admin</b> account. This account can add users, turn on maintenance mode and see the audit log.</p>`
		if msg != "" {
			body += `<div class="err">` + esc(msg) + `</div>`
		}
		body += `<form method="post" action="/setup">
<label for="n">Your name</label><input id="n" name="name" required value="` + esc(r.FormValue("name")) + `">
<label for="u">Username</label><input id="u" name="username" autocomplete="username" required value="` + esc(r.FormValue("username")) + `">
<label for="p">Password (at least 10 characters)</label><input id="p" name="password" type="password" autocomplete="new-password" required>
<label for="p2">Confirm password</label><input id="p2" name="password2" type="password" autocomplete="new-password" required>
<button type="submit">Create Global Admin</button></form>`
		renderPage(w, http.StatusOK, "Setup", body, false)
	}
	if ip := net.ParseIP(clientIP(r)); ip == nil || !ip.IsLoopback() {
		renderPage(w, http.StatusForbidden, "Setup", `<h1>Setup not finished</h1><p>This website hasn't been set up yet. First-time setup can only be done on the server itself – open the program on the server and the setup page will appear.</p>`, false)
		return
	}
	if r.Method != http.MethodPost {
		form("")
		return
	}
	name := strings.TrimSpace(r.FormValue("name"))
	un := strings.ToLower(strings.TrimSpace(r.FormValue("username")))
	pw := r.FormValue("password")
	if name == "" || !usernameRe.MatchString(un) {
		form("Enter your name and a username of 3–64 characters (letters, numbers, . _ - @).")
		return
	}
	if err := validPassword(pw); err != nil {
		form(err.Error())
		return
	}
	if pw != r.FormValue("password2") {
		form("The two passwords don't match.")
		return
	}
	sv.st.mu.Lock()
	if len(sv.st.users) > 0 {
		sv.st.mu.Unlock()
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	u := &User{ID: randHex(8), Username: un, Name: name, Role: "admin", Created: time.Now().UTC(), LastLogin: time.Now().UTC()}
	setPassword(u, pw)
	sv.st.users = append(sv.st.users, u)
	err := sv.st.saveUsers()
	sv.st.mu.Unlock()
	if err != nil {
		form("Couldn't save: " + err.Error())
		return
	}
	sv.st.audit(un, clientIP(r), "setup.admin_created", "Global Admin account created during first-time setup")
	sv.startSession(w, r, u)
	http.Redirect(w, r, "/", http.StatusSeeOther)
}

func (sv *Server) checkLock(ip string) (bool, time.Duration) {
	sv.st.mu.Lock()
	defer sv.st.mu.Unlock()
	f := sv.st.fails[ip]
	if f != nil && time.Now().Before(f.Until) {
		return true, time.Until(f.Until)
	}
	return false, 0
}

func (sv *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	if !sv.hasUsers() {
		http.Redirect(w, r, "/setup", http.StatusSeeOther)
		return
	}
	next := r.FormValue("next")
	if r.Method != http.MethodPost {
		if u, _ := sv.currentUser(r); u != nil {
			http.Redirect(w, r, safeNext(next), http.StatusSeeOther)
			return
		}
		sv.loginPage(w, r, "", next, http.StatusOK)
		return
	}
	ip := clientIP(r)
	if locked, d := sv.checkLock(ip); locked {
		sv.st.audit(r.FormValue("username"), ip, "login.blocked", "Sign-in attempt while locked out")
		sv.loginPage(w, r, fmt.Sprintf("Too many failed attempts. Try again in %d minutes.", int(d.Minutes())+1), next, http.StatusTooManyRequests)
		return
	}
	un := strings.ToLower(strings.TrimSpace(r.FormValue("username")))
	pw := r.FormValue("password")
	sv.st.mu.Lock()
	u := sv.st.userByName(un)
	var cand *User
	if u != nil {
		c := *u
		cand = &c
	}
	sv.st.mu.Unlock()
	ok := false
	if cand != nil {
		ok = checkPassword(cand, pw)
	} else {
		checkPassword(dummyUser, pw) // equalise timing
	}
	if !ok || cand.Disabled {
		sv.st.mu.Lock()
		f := sv.st.fails[ip]
		if f == nil || time.Since(f.Last) > 30*time.Minute {
			f = &failInfo{}
			sv.st.fails[ip] = f
		}
		f.Count++
		f.Last = time.Now()
		locked := false
		if f.Count >= 5 {
			f.Until = time.Now().Add(15 * time.Minute)
			f.Count = 0
			locked = true
		}
		sv.st.mu.Unlock()
		reason := "wrong username or password"
		if ok && cand.Disabled {
			reason = "account is disabled"
		}
		sv.st.audit(un, ip, "login.failed", reason)
		if locked {
			sv.st.audit(un, ip, "login.locked", "5 failed attempts – IP locked for 15 minutes")
		}
		time.Sleep(400 * time.Millisecond)
		sv.loginPage(w, r, "That username and password didn't match an active account.", next, http.StatusUnauthorized)
		return
	}
	sv.st.mu.Lock()
	delete(sv.st.fails, ip)
	if real := sv.st.userByID(cand.ID); real != nil {
		real.LastLogin = time.Now().UTC()
		sv.st.saveUsers()
	}
	sv.st.mu.Unlock()
	sv.st.audit(un, ip, "login.success", roleLabel(cand.Role))
	sv.startSession(w, r, cand)
	if cand.MustChange {
		http.Redirect(w, r, "/change-password", http.StatusSeeOther)
		return
	}
	http.Redirect(w, r, safeNext(next), http.StatusSeeOther)
}

func (sv *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	u, tok := sv.currentUser(r)
	if tok != "" {
		sv.st.mu.Lock()
		delete(sv.st.sessions, tok)
		sv.st.mu.Unlock()
	}
	if u != nil {
		sv.st.audit(u.Username, clientIP(r), "logout", "")
	}
	clearCookie(w, r)
	http.Redirect(w, r, "/login?out=1", http.StatusSeeOther)
}

func (sv *Server) handleChangePassword(w http.ResponseWriter, r *http.Request) {
	u, tok := sv.currentUser(r)
	if u == nil {
		http.Redirect(w, r, "/login", http.StatusSeeOther)
		return
	}
	form := func(msg string) {
		body := `<h1>Choose a new password</h1>`
		if u.MustChange {
			body += `<p>Hi ` + esc(u.Name) + `, please replace the temporary password you were given.</p>`
		}
		if msg != "" {
			body += `<div class="err">` + esc(msg) + `</div>`
		}
		body += `<form method="post" action="/change-password">
<label for="c">Current password</label><input id="c" name="current" type="password" autocomplete="current-password" required>
<label for="p">New password (at least 10 characters)</label><input id="p" name="password" type="password" autocomplete="new-password" required>
<label for="p2">Confirm new password</label><input id="p2" name="password2" type="password" autocomplete="new-password" required>
<button type="submit">Save password</button></form><p class="small"><a href="/logout">Sign out</a></p>`
		renderPage(w, http.StatusOK, "Change password", body, false)
	}
	if r.Method != http.MethodPost {
		form("")
		return
	}
	if msg := sv.changePassword(u, tok, r.FormValue("current"), r.FormValue("password"), r.FormValue("password2"), clientIP(r)); msg != "" {
		form(msg)
		return
	}
	http.Redirect(w, r, "/", http.StatusSeeOther)
}

func (sv *Server) changePassword(u *User, keepTok, cur, pw, pw2, ip string) string {
	if !checkPassword(u, cur) {
		sv.st.audit(u.Username, ip, "password.change_failed", "current password incorrect")
		return "Your current password is incorrect."
	}
	if err := validPassword(pw); err != nil {
		return err.Error()
	}
	if pw != pw2 {
		return "The two new passwords don't match."
	}
	if pw == cur {
		return "Choose a password different from your current one."
	}
	sv.st.mu.Lock()
	real := sv.st.userByID(u.ID)
	if real == nil {
		sv.st.mu.Unlock()
		return "Account not found."
	}
	setPassword(real, pw)
	real.MustChange = false
	for k, v := range sv.st.sessions { // sign out other devices
		if v.UserID == u.ID && k != keepTok {
			delete(sv.st.sessions, k)
		}
	}
	sv.st.saveUsers()
	sv.st.mu.Unlock()
	sv.st.audit(u.Username, ip, "password.changed", "User changed their own password (other sessions signed out)")
	return ""
}

// ---------- JSON API ----------

func writeJSONResp(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func apiErr(w http.ResponseWriter, status int, msg string) {
	writeJSONResp(w, status, map[string]string{"error": msg})
}

type apiCtx struct {
	u   *User
	tok string
	ip  string
}

// level: "" = anyone allowed to see the site, "staff" = editor/admin, "admin" = Global Admin, "self" = any signed-in user
func (sv *Server) api(level string, fn func(w http.ResponseWriter, r *http.Request, c *apiCtx)) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Header.Get("X-DUC") != "1" {
			apiErr(w, http.StatusForbidden, "Missing request header")
			return
		}
		u, tok := sv.currentUser(r)
		c := &apiCtx{u: u, tok: tok, ip: clientIP(r)}
		if u != nil && u.MustChange && level != "none" {
			apiErr(w, http.StatusForbidden, "Password change required")
			return
		}
		switch level {
		case "":
			sv.st.mu.Lock()
			set := sv.st.settings
			sv.st.mu.Unlock()
			staff := u != nil && (u.Role == "admin" || u.Role == "editor")
			if (set.RequireLogin && u == nil) || (set.Maintenance && !staff) {
				apiErr(w, http.StatusUnauthorized, "Sign in required")
				return
			}
		case "self":
			if u == nil {
				apiErr(w, http.StatusUnauthorized, "Sign in required")
				return
			}
		case "staff":
			if u == nil || (u.Role != "admin" && u.Role != "editor") {
				apiErr(w, http.StatusForbidden, "Editors and admins only")
				return
			}
		case "admin":
			if u == nil || u.Role != "admin" {
				apiErr(w, http.StatusForbidden, "Global Admin only")
				return
			}
		}
		r.Body = http.MaxBytesReader(w, r.Body, 8<<20)
		fn(w, r, c)
	}
}

func (sv *Server) apiMe(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	sv.st.mu.Lock()
	set := sv.st.settings
	sv.st.mu.Unlock()
	var pu any
	if c.u != nil {
		pu = c.u.Public()
	}
	writeJSONResp(w, 200, map[string]any{"user": pu, "settings": map[string]any{"maintenance": set.Maintenance, "requireLogin": set.RequireLogin}, "version": appVersion})
}

func (sv *Server) apiContent(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	sv.st.mu.Lock()
	b := sv.st.content
	sv.st.mu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	if len(b) == 0 {
		w.Write([]byte("null"))
		return
	}
	w.Write(b)
}

func (sv *Server) apiContentPut(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	var buf bytes.Buffer
	if _, err := buf.ReadFrom(r.Body); err != nil {
		apiErr(w, 413, "Content too large (max 8 MB)")
		return
	}
	var newMap map[string]json.RawMessage
	if err := json.Unmarshal(buf.Bytes(), &newMap); err != nil {
		apiErr(w, 400, "Invalid content")
		return
	}
	delete(newMap, "applications")
	delete(newMap, "settings")
	clean, _ := json.Marshal(newMap)
	sv.st.mu.Lock()
	var oldMap map[string]json.RawMessage
	json.Unmarshal(sv.st.content, &oldMap)
	var changed []string
	for k, v := range newMap {
		if o, ok := oldMap[k]; !ok || !jsonEqual(o, v) {
			changed = append(changed, k)
		}
	}
	for k := range oldMap {
		if _, ok := newMap[k]; !ok {
			changed = append(changed, k)
		}
	}
	sort.Strings(changed)
	err := os.WriteFile(sv.st.path("content.json.tmp"), clean, 0600)
	if err == nil {
		err = os.Rename(sv.st.path("content.json.tmp"), sv.st.path("content.json"))
	}
	if err == nil {
		sv.st.content = clean
	}
	sv.st.mu.Unlock()
	if err != nil {
		apiErr(w, 500, "Couldn't save: "+err.Error())
		return
	}
	det := "no changes"
	if len(changed) > 0 {
		det = "Sections changed: " + strings.Join(changed, ", ")
	}
	sv.st.audit(c.u.Username, c.ip, "content.published", det)
	writeJSONResp(w, 200, map[string]any{"ok": true, "changed": changed})
}

func jsonEqual(a, b json.RawMessage) bool {
	var x, y any
	if json.Unmarshal(a, &x) != nil || json.Unmarshal(b, &y) != nil {
		return bytes.Equal(a, b)
	}
	xa, _ := json.Marshal(x)
	ya, _ := json.Marshal(y)
	return bytes.Equal(xa, ya)
}

func clip(s string, n int) string {
	s = strings.TrimSpace(s)
	if len([]rune(s)) > n {
		return string([]rune(s)[:n])
	}
	return s
}

func (sv *Server) apiEnquiryPost(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	var e Enquiry
	if err := json.NewDecoder(r.Body).Decode(&e); err != nil {
		apiErr(w, 400, "Invalid enquiry")
		return
	}
	e.Name, e.Contact, e.Type, e.Postcode, e.Purpose, e.Amount = clip(e.Name, 120), clip(e.Contact, 200), clip(e.Type, 60), clip(e.Postcode, 12), clip(e.Purpose, 4000), clip(e.Amount, 20)
	if e.Name == "" || e.Contact == "" || e.Purpose == "" {
		apiErr(w, 400, "Please fill in your name, contact details and what the grant is for")
		return
	}
	e.ID, e.Date, e.Status = randHex(6), time.Now().UTC(), "new"
	if c.u != nil {
		e.By = c.u.Username
	}
	sv.st.mu.Lock()
	recent := 0
	for _, x := range sv.st.enquiries {
		if time.Since(x.Date) < time.Hour && x.By == e.By && e.By != "" {
			recent++
		}
	}
	if recent >= 10 {
		sv.st.mu.Unlock()
		apiErr(w, 429, "Too many enquiries – please try later")
		return
	}
	sv.st.enquiries = append([]*Enquiry{&e}, sv.st.enquiries...)
	sv.st.saveEnquiries()
	sv.st.mu.Unlock()
	who := e.By
	if who == "" {
		who = "(visitor)"
	}
	sv.st.audit(who, c.ip, "enquiry.received", "Enquiry "+e.ID+" from "+e.Name)
	writeJSONResp(w, 200, map[string]any{"ok": true})
}

func (sv *Server) apiEnquiries(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	sv.st.mu.Lock()
	defer sv.st.mu.Unlock()
	if sv.st.enquiries == nil {
		writeJSONResp(w, 200, []any{})
		return
	}
	writeJSONResp(w, 200, sv.st.enquiries)
}

func (sv *Server) apiEnquiryItem(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	id := r.PathValue("id")
	sv.st.mu.Lock()
	idx := -1
	for i, e := range sv.st.enquiries {
		if e.ID == id {
			idx = i
		}
	}
	if idx < 0 {
		sv.st.mu.Unlock()
		apiErr(w, 404, "Not found")
		return
	}
	e := sv.st.enquiries[idx]
	if r.Method == http.MethodDelete {
		sv.st.enquiries = append(sv.st.enquiries[:idx], sv.st.enquiries[idx+1:]...)
		sv.st.saveEnquiries()
		sv.st.mu.Unlock()
		sv.st.audit(c.u.Username, c.ip, "enquiry.deleted", "Enquiry "+id+" from "+e.Name)
		writeJSONResp(w, 200, map[string]any{"ok": true})
		return
	}
	var body struct{ Status string }
	json.NewDecoder(r.Body).Decode(&body)
	if body.Status != "new" && body.Status != "review" && body.Status != "approved" && body.Status != "declined" {
		sv.st.mu.Unlock()
		apiErr(w, 400, "Invalid status")
		return
	}
	old := e.Status
	e.Status = body.Status
	sv.st.saveEnquiries()
	sv.st.mu.Unlock()
	sv.st.audit(c.u.Username, c.ip, "enquiry.status", fmt.Sprintf("Enquiry %s (%s): %s → %s", id, e.Name, old, body.Status))
	writeJSONResp(w, 200, map[string]any{"ok": true})
}

func (sv *Server) apiSettings(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	if r.Method == http.MethodGet {
		sv.st.mu.Lock()
		s := sv.st.settings
		sv.st.mu.Unlock()
		writeJSONResp(w, 200, s)
		return
	}
	var n Settings
	if err := json.NewDecoder(r.Body).Decode(&n); err != nil {
		apiErr(w, 400, "Invalid settings")
		return
	}
	n.MaintTitle, n.MaintMessage, n.MaintUntil = clip(n.MaintTitle, 120), clip(n.MaintMessage, 2000), clip(n.MaintUntil, 80)
	if n.MaintTitle == "" {
		n.MaintTitle = "We'll be back soon"
	}
	if n.SessionHours < 1 || n.SessionHours > 720 {
		n.SessionHours = 12
	}
	sv.st.mu.Lock()
	o := sv.st.settings
	sv.st.settings = n
	err := sv.st.saveSettings()
	sv.st.mu.Unlock()
	if err != nil {
		apiErr(w, 500, "Couldn't save settings")
		return
	}
	onoff := func(b bool) string {
		if b {
			return "ON"
		}
		return "OFF"
	}
	if o.Maintenance != n.Maintenance {
		sv.st.audit(c.u.Username, c.ip, "maintenance."+strings.ToLower(onoff(n.Maintenance)), "Maintenance mode turned "+onoff(n.Maintenance))
	}
	if o.RequireLogin != n.RequireLogin {
		sv.st.audit(c.u.Username, c.ip, "access.require_login", "Password protection for the whole site turned "+onoff(n.RequireLogin))
	}
	var ch []string
	if o.MaintTitle != n.MaintTitle || o.MaintMessage != n.MaintMessage || o.MaintUntil != n.MaintUntil {
		ch = append(ch, "maintenance page text")
	}
	if o.SessionHours != n.SessionHours {
		ch = append(ch, fmt.Sprintf("session length %dh → %dh", o.SessionHours, n.SessionHours))
	}
	if len(ch) > 0 {
		sv.st.audit(c.u.Username, c.ip, "settings.changed", strings.Join(ch, "; "))
	}
	writeJSONResp(w, 200, map[string]any{"ok": true})
}

func (sv *Server) apiUsers(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	if r.Method == http.MethodGet {
		sv.st.mu.Lock()
		out := []PublicUser{}
		for _, u := range sv.st.users {
			out = append(out, u.Public())
		}
		sv.st.mu.Unlock()
		writeJSONResp(w, 200, out)
		return
	}
	var b struct{ Username, Name, Role, Password string }
	if err := json.NewDecoder(r.Body).Decode(&b); err != nil {
		apiErr(w, 400, "Invalid request")
		return
	}
	b.Username = strings.ToLower(strings.TrimSpace(b.Username))
	b.Name = clip(b.Name, 120)
	if b.Name == "" || !usernameRe.MatchString(b.Username) {
		apiErr(w, 400, "Enter a name and a username of 3–64 characters (letters, numbers, . _ - @)")
		return
	}
	if !validRole(b.Role) {
		apiErr(w, 400, "Invalid role")
		return
	}
	if err := validPassword(b.Password); err != nil {
		apiErr(w, 400, "Temporary password: "+err.Error())
		return
	}
	sv.st.mu.Lock()
	if sv.st.userByName(b.Username) != nil {
		sv.st.mu.Unlock()
		apiErr(w, 409, "That username is already taken")
		return
	}
	u := &User{ID: randHex(8), Username: b.Username, Name: b.Name, Role: b.Role, MustChange: true, Created: time.Now().UTC()}
	setPassword(u, b.Password)
	sv.st.users = append(sv.st.users, u)
	sv.st.saveUsers()
	pu := u.Public()
	sv.st.mu.Unlock()
	sv.st.audit(c.u.Username, c.ip, "user.created", fmt.Sprintf("Created %s (%s) as %s", b.Username, b.Name, roleLabel(b.Role)))
	writeJSONResp(w, 200, pu)
}

func (sv *Server) apiUserItem(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	id := r.PathValue("id")
	sv.st.mu.Lock()
	u := sv.st.userByID(id)
	if u == nil {
		sv.st.mu.Unlock()
		apiErr(w, 404, "User not found")
		return
	}
	if r.Method == http.MethodDelete {
		if u.ID == c.u.ID {
			sv.st.mu.Unlock()
			apiErr(w, 400, "You can't delete your own account")
			return
		}
		if u.Role == "admin" && !u.Disabled && sv.st.activeAdmins() <= 1 {
			sv.st.mu.Unlock()
			apiErr(w, 400, "You can't delete the last Global Admin")
			return
		}
		for i, x := range sv.st.users {
			if x.ID == id {
				sv.st.users = append(sv.st.users[:i], sv.st.users[i+1:]...)
				break
			}
		}
		sv.st.revokeSessions(id)
		sv.st.saveUsers()
		sv.st.mu.Unlock()
		sv.st.audit(c.u.Username, c.ip, "user.deleted", fmt.Sprintf("Deleted %s (%s)", u.Username, u.Name))
		writeJSONResp(w, 200, map[string]any{"ok": true})
		return
	}
	var b struct {
		Name     *string
		Role     *string
		Disabled *bool
		Password *string
	}
	if err := json.NewDecoder(r.Body).Decode(&b); err != nil {
		sv.st.mu.Unlock()
		apiErr(w, 400, "Invalid request")
		return
	}
	var notes []string
	if b.Role != nil && *b.Role != u.Role {
		if !validRole(*b.Role) {
			sv.st.mu.Unlock()
			apiErr(w, 400, "Invalid role")
			return
		}
		if u.Role == "admin" && !u.Disabled && sv.st.activeAdmins() <= 1 {
			sv.st.mu.Unlock()
			apiErr(w, 400, "There must always be at least one active Global Admin")
			return
		}
		notes = append(notes, fmt.Sprintf("role %s → %s", roleLabel(u.Role), roleLabel(*b.Role)))
		u.Role = *b.Role
		sv.st.revokeSessions(u.ID)
	}
	if b.Disabled != nil && *b.Disabled != u.Disabled {
		if *b.Disabled {
			if u.ID == c.u.ID {
				sv.st.mu.Unlock()
				apiErr(w, 400, "You can't disable your own account")
				return
			}
			if u.Role == "admin" && sv.st.activeAdmins() <= 1 {
				sv.st.mu.Unlock()
				apiErr(w, 400, "You can't disable the last Global Admin")
				return
			}
			sv.st.revokeSessions(u.ID)
			notes = append(notes, "account disabled")
		} else {
			notes = append(notes, "account enabled")
		}
		u.Disabled = *b.Disabled
	}
	if b.Name != nil && clip(*b.Name, 120) != u.Name && clip(*b.Name, 120) != "" {
		notes = append(notes, fmt.Sprintf("name '%s' → '%s'", u.Name, clip(*b.Name, 120)))
		u.Name = clip(*b.Name, 120)
	}
	action := "user.updated"
	if b.Password != nil {
		if err := validPassword(*b.Password); err != nil {
			sv.st.mu.Unlock()
			apiErr(w, 400, "Temporary password: "+err.Error())
			return
		}
		setPassword(u, *b.Password)
		u.MustChange = true
		sv.st.revokeSessions(u.ID)
		action = "user.password_reset"
		notes = append(notes, "password reset (must change at next sign-in)")
	}
	sv.st.saveUsers()
	pu := u.Public()
	sv.st.mu.Unlock()
	if len(notes) > 0 {
		sv.st.audit(c.u.Username, c.ip, action, u.Username+": "+strings.Join(notes, "; "))
	}
	writeJSONResp(w, 200, pu)
}

func (sv *Server) apiMyPassword(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	var b struct{ Current, Password, Password2 string }
	json.NewDecoder(r.Body).Decode(&b)
	if msg := sv.changePassword(c.u, c.tok, b.Current, b.Password, b.Password2, c.ip); msg != "" {
		apiErr(w, 400, msg)
		return
	}
	writeJSONResp(w, 200, map[string]any{"ok": true})
}

func (sv *Server) apiSessions(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	sv.st.mu.Lock()
	type row struct {
		Username string    `json:"username"`
		Name     string    `json:"name"`
		Role     string    `json:"role"`
		Since    time.Time `json:"since"`
	}
	out := []row{}
	for _, s := range sv.st.sessions {
		if time.Now().After(s.Expires) {
			continue
		}
		if u := sv.st.userByID(s.UserID); u != nil {
			out = append(out, row{u.Username, u.Name, u.Role, s.Created})
		}
	}
	sv.st.mu.Unlock()
	sort.Slice(out, func(i, j int) bool { return out[i].Since.After(out[j].Since) })
	writeJSONResp(w, 200, out)
}

func filterAudit(all []AuditEntry, r *http.Request) []AuditEntry {
	q := strings.ToLower(strings.TrimSpace(r.URL.Query().Get("q")))
	act := r.URL.Query().Get("action")
	out := []AuditEntry{}
	for i := len(all) - 1; i >= 0; i-- {
		e := all[i]
		if act != "" && !strings.HasPrefix(e.Action, act) {
			continue
		}
		if q != "" && !strings.Contains(strings.ToLower(e.User+" "+e.IP+" "+e.Action+" "+e.Details), q) {
			continue
		}
		out = append(out, e)
	}
	return out
}

func (sv *Server) apiAudit(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	list := filterAudit(sv.st.readAudit(), r)
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit <= 0 || limit > 2000 {
		limit = 300
	}
	total := len(list)
	if len(list) > limit {
		list = list[:limit]
	}
	writeJSONResp(w, 200, map[string]any{"total": total, "entries": list})
}

func (sv *Server) apiAuditCSV(w http.ResponseWriter, r *http.Request, c *apiCtx) {
	list := filterAudit(sv.st.readAudit(), r)
	sv.st.audit(c.u.Username, c.ip, "audit.exported", fmt.Sprintf("%d entries exported to CSV", len(list)))
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="duc-audit-log-%s.csv"`, time.Now().Format("2006-01-02")))
	w.Write([]byte{0xEF, 0xBB, 0xBF})
	cw := csv.NewWriter(w)
	cw.Write([]string{"Time (UTC)", "User", "IP address", "Action", "Details"})
	for _, e := range list {
		d := e.Details
		if strings.ContainsAny(d[:min(1, len(d))], "=+-@") {
			d = "'" + d
		}
		cw.Write([]string{e.Time.Format("2006-01-02 15:04:05"), e.User, e.IP, e.Action, d})
	}
	cw.Flush()
}

// ---------- main ----------

func openBrowser(url string) {
	if runtime.GOOS == "windows" {
		exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
	} else if runtime.GOOS == "darwin" {
		exec.Command("open", url).Start()
	}
}

func main() {
	exe, _ := os.Executable()
	baseDir := filepath.Dir(exe)
	dataFlag := flag.String("data", filepath.Join(baseDir, "duc-data"), "data folder")
	listenFlag := flag.String("listen", "", "address to listen on (default from duc-data/config.json or 127.0.0.1:8112)")
	service := flag.Bool("service", false, "running as a background task (don't open a browser)")
	resetAdmin := flag.String("reset-admin", "", "emergency: set a new password for this admin username and exit")
	flag.Parse()

	st := &Store{dir: *dataFlag}
	if err := st.load(); err != nil {
		log.Fatal("Can't open data folder: ", err)
	}
	if *resetAdmin != "" {
		fmt.Print("New password (min 10 chars): ")
		pw, _ := bufio.NewReader(os.Stdin).ReadString('\n')
		pw = strings.TrimRight(pw, "\r\n")
		u := st.userByName(*resetAdmin)
		if u == nil || u.Role != "admin" {
			log.Fatal("No Global Admin with that username")
		}
		if err := validPassword(pw); err != nil {
			log.Fatal(err)
		}
		setPassword(u, pw)
		u.Disabled = false
		st.saveUsers()
		st.audit("(server console)", "local", "user.password_reset", u.Username+": emergency reset from the server console")
		fmt.Println("Password updated.")
		return
	}

	cfg := Config{Listen: "127.0.0.1:8112"}
	if err := readJSON(st.path("config.json"), &cfg); err != nil {
		writeJSON(st.path("config.json"), cfg)
	}
	if *listenFlag != "" {
		cfg.Listen = *listenFlag
	}

	sv := &Server{st: st}
	mux := http.NewServeMux()
	mux.HandleFunc("/", sv.handleRoot)
	mux.HandleFunc("/assets/", sv.handleAsset)
	mux.HandleFunc("/setup", sv.handleSetup)
	mux.HandleFunc("/login", sv.handleLogin)
	mux.HandleFunc("/logout", sv.handleLogout)
	mux.HandleFunc("/change-password", sv.handleChangePassword)
	mux.HandleFunc("GET /api/me", sv.api("none", sv.apiMe))
	mux.HandleFunc("GET /api/content", sv.api("", sv.apiContent))
	mux.HandleFunc("PUT /api/content", sv.api("staff", sv.apiContentPut))
	mux.HandleFunc("POST /api/enquiries", sv.api("", sv.apiEnquiryPost))
	mux.HandleFunc("GET /api/enquiries", sv.api("staff", sv.apiEnquiries))
	mux.HandleFunc("PUT /api/enquiries/{id}", sv.api("staff", sv.apiEnquiryItem))
	mux.HandleFunc("DELETE /api/enquiries/{id}", sv.api("staff", sv.apiEnquiryItem))
	mux.HandleFunc("GET /api/settings", sv.api("admin", sv.apiSettings))
	mux.HandleFunc("PUT /api/settings", sv.api("admin", sv.apiSettings))
	mux.HandleFunc("GET /api/users", sv.api("admin", sv.apiUsers))
	mux.HandleFunc("POST /api/users", sv.api("admin", sv.apiUsers))
	mux.HandleFunc("PUT /api/users/{id}", sv.api("admin", sv.apiUserItem))
	mux.HandleFunc("DELETE /api/users/{id}", sv.api("admin", sv.apiUserItem))
	mux.HandleFunc("GET /api/sessions", sv.api("admin", sv.apiSessions))
	mux.HandleFunc("POST /api/account/password", sv.api("self", sv.apiMyPassword))
	mux.HandleFunc("GET /api/audit", sv.api("admin", sv.apiAudit))
	mux.HandleFunc("GET /api/audit.csv", sv.api("admin", sv.apiAuditCSV))

	// clean up expired sessions
	go func() {
		for range time.Tick(10 * time.Minute) {
			st.mu.Lock()
			for k, v := range st.sessions {
				if time.Now().After(v.Expires) {
					delete(st.sessions, k)
				}
			}
			st.mu.Unlock()
		}
	}()

	srv := &http.Server{Addr: cfg.Listen, Handler: secHeaders(mux), ReadHeaderTimeout: 10 * time.Second, ReadTimeout: 60 * time.Second, WriteTimeout: 60 * time.Second}
	ln, err := net.Listen("tcp", cfg.Listen)
	if err != nil {
		log.Fatalf("Can't listen on %s: %v (is it already running?)", cfg.Listen, err)
	}
	host := cfg.Listen
	if strings.HasPrefix(host, "0.0.0.0") || strings.HasPrefix(host, ":") {
		host = "127.0.0.1" + host[strings.LastIndex(host, ":"):]
	}
	url := "http://" + host + "/"
	fmt.Println("==============================================")
	fmt.Println(" Droitwich United Charities website  v" + appVersion)
	fmt.Println(" Running at " + url)
	fmt.Println(" Data folder: " + st.dir)
	fmt.Println(" Keep this window open (or run Install-Startup.bat).")
	fmt.Println("==============================================")
	st.audit("(system)", "local", "server.started", "Website server started (v"+appVersion+") on "+cfg.Listen)
	if !*service {
		if len(st.users) == 0 {
			fmt.Println(" First run: opening the setup page in your browser…")
			go func() { time.Sleep(700 * time.Millisecond); openBrowser(url + "setup") }()
		} else {
			go func() { time.Sleep(700 * time.Millisecond); openBrowser(url) }()
		}
	}
	log.Fatal(srv.Serve(ln))
}
