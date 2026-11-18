@echo off
:: Builds DUC-Website-cPanel.zip – upload it to public_html in cPanel File Manager and extract.
cd /d "%~dp0"
if exist dist\cpanel rmdir /s /q dist\cpanel
mkdir dist\cpanel\app dist\cpanel\assets
copy /y cpanel\index.php dist\cpanel\ >nul
copy /y cpanel\.htaccess dist\cpanel\ >nul
copy /y cpanel\INSTALL.txt dist\cpanel\ >nul
xcopy /e /i /y /q cpanel\app dist\cpanel\app >nul
copy /y web\site.html dist\cpanel\app\site.html >nul
xcopy /e /i /y /q cpanel\assets dist\cpanel\assets >nul
copy /y web\duc-logo.webp dist\cpanel\assets\duc-logo.webp >nul
if exist DUC-Website-cPanel.zip del DUC-Website-cPanel.zip
powershell -NoProfile -Command "Compress-Archive -Path 'dist\cpanel\*' -DestinationPath 'DUC-Website-cPanel.zip' -Force"
if exist dist\trustee_duc rmdir /s /q dist\trustee_duc
xcopy /e /i /y /q /h dist\cpanel dist\trustee_duc >nul
if exist trustee_duc.zip del trustee_duc.zip
powershell -NoProfile -Command "Compress-Archive -Path 'dist\trustee_duc' -DestinationPath 'trustee_duc.zip' -Force"
echo Built DUC-Website-cPanel.zip and trustee_duc.zip
pause
