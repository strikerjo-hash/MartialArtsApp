@echo off
REM ───────────────────────────────────────────────────
REM  Rebuild Tailwind CSS  (run after adding new classes)
REM ───────────────────────────────────────────────────

REM Download standalone CLI if missing (~40 MB, no Node required)
if not exist tailwindcss.exe (
    echo Downloading Tailwind CSS standalone CLI...
    curl -sL -o tailwindcss.exe https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-windows-x64.exe
)

echo Building Tailwind CSS...
tailwindcss.exe -i assets/css/input.css -o assets/css/tailwind.css --minify

echo Done!
pause
