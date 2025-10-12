#!/usr/bin/env python3
"""
Fix undefined 'Group' array key warnings in tacview.php
"""

import re

# Read the file
with open('public/tacview.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Pattern to find lines that access Group without isset check
# Match: $event["PrimaryObject"]["Group"] or $event["SecondaryObject"]["Group"] or $event["ParentObject"]["Group"]
# when it's being assigned directly without isset

replacements = [
    # Fix line 507 and similar patterns for weaponOwners
    (
        r"'group' => \$event\[\"(\w+)\"\]\[\"Group\"\],",
        r"'group' => isset($event[\"\1\"][\"Group\"]) ? $event[\"\1\"][\"Group\"] : \"Unknown\","
    ),
    # Fix assignments to $this->stats
    (
        r"(\$this->stats\[\$\w+\]\[\"Group\"\])\s+=\s+\$event\[\"(\w+)\"\]\[\"Group\"\];",
        r"\1    = isset($event[\"\2\"][\"Group\"]) ? $event[\"\2\"][\"Group\"] : \"Unknown\";"
    ),
]

# Apply replacements
for pattern, replacement in replacements:
    content = re.sub(pattern, replacement, content)

# Write the fixed content
with open('public/tacview.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Fixed Group key access issues!")
