<?php
// ============================================================
// VIKOBA - Offline Fallback Page (PWA)
// ============================================================
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Offline — VIKOBA</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { 
      font-family:Arial,sans-serif; 
      background:#f4f4f4; 
      display:flex; 
      align-items:center; 
      justify-content:center; 
      min-height:100vh;
      padding:20px;
    }
    .offline-card {
      background:#fff;
      border-radius:16px;
      padding:40px;
      text-align:center;
      max-width:400px;
      box-shadow:0 4px 20px rgba(0,0,0,0.08);
    }
    .icon { font-size:64px; color:#185FA5; margin-bottom:16px; }
    h1 { font-size:20px; color:#333; margin-bottom:8px; }
    p { color:#666; font-size:14px; line-height:1.6; margin-bottom:24px; }
    .btn {
      display:inline-block;
      background:#185FA5;
      color:#fff;
      text-decoration:none;
      padding:10px 24px;
      border-radius:8px;
      font-size:14px;
    }
    .btn:hover { background:#134580; }
  </style>
</head>
<body>
  <div class="offline-card">
    <div class="icon">
      <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#185FA5" stroke-width="1.5">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
      </svg>
    </div>
    <h1>You're Offline</h1>
    <p>VIKOBA is currently unavailable offline for this page.<br>
       Please check your internet connection and try again.<br>
       Some pages may still be available from cache.</p>
    <a href="/vikoba/" class="btn" onclick="window.location.reload()">Try Again</a>
  </div>
</body>
</html>