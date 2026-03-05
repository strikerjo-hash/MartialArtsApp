import os

path = os.path.expanduser("~/OneDrive/Documents/GitHub/MartialArtsApp/reports_new.php")

with open(path, "r") as f:
    content = f.read()

# We will write the entire new file
lines = []

lines.append("""<?php
require_once 'config.php';
requireLogin();

// === Date Range Filter ===
\ = \['range'] ?? 'this_month';
\ = \['date_from'] ?? '';
\ = \['date_to'] ?? '';
""")

print("Script created")
