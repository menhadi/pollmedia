<?php

return [
    'cpgrams' => ['name' => 'CPGRAMS', 'url' => 'https://pgportal.gov.in/', 'scope' => 'Central government public grievances; check the portal’s jurisdiction and exclusions.', 'integration' => 'API Setu listing found; access and approval not yet established.', 'source' => 'https://directory.apisetu.gov.in/api-collection/pgportal', 'checked_on' => '2026-10-15'],
    'up_jansunwai' => ['name' => 'UP Jansunwai / Samadhan', 'url' => 'https://jansunwai.up.nic.in/', 'scope' => 'Uttar Pradesh government services.', 'integration' => 'Official submission and tracking links; citizen completes OTP/CAPTCHA on the official portal.', 'source' => 'https://jansunwai.up.nic.in/ComplaintTracker', 'checked_on' => '2026-10-15'],
    'nch' => ['name' => 'National Consumer Helpline', 'url' => 'https://consumerhelpline.gov.in/', 'scope' => 'Consumer disputes with businesses; not the general route for civic infrastructure complaints.', 'integration' => 'Official portal handoff; no approved Pollmedia API connection.', 'source' => 'https://consumerhelpline.gov.in/public/convergenceprogram', 'checked_on' => '2026-10-15'],
];
