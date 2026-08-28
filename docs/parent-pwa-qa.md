# Parent PWA QA

Use this checklist when checking the parent portal as an installable PWA.

## Local Lighthouse audit

1. Start the Laravel server:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start-local.ps1
```

2. Open `http://127.0.0.1:5500/login` in Chrome or Edge.
3. Log in as the demo parent account.
4. Open `http://127.0.0.1:5500/parent/dashboard`.
5. Run Lighthouse from Chrome DevTools on the logged-in tab, or use the included script. Lighthouse 12+ may no longer expose the old `pwa` category.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\audit-pwa.ps1
```

If the audit is run while logged out, Laravel redirects to `/login`, so the audit will not fully validate the parent PWA shell.

Latest local CLI audit: Lighthouse 11.7.1 reached `/parent/dashboard` and returned PWA score `1`.

## Real phone install check

Use an HTTPS staging URL for a real phone test. `127.0.0.1` only points to the phone itself, and a plain LAN HTTP address is usually not treated as a secure installable PWA origin.

1. Open the HTTPS parent portal URL on the phone.
2. Sign in as a parent.
3. Confirm the browser offers install/add-to-home-screen.
4. Install the app and open it from the home screen.
5. Confirm it opens in standalone app mode, not inside a normal browser tab.
6. Turn off network and open a parent page. The offline page should appear for unavailable parent navigation.
7. Turn network back on and confirm dashboard, messages, notifications, and the side menu still work.
