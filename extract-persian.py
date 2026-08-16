#!/usr/bin/env python3
"""
VELORA i18n Helper Script
=========================
Run this after adding new Persian content to extract untranslated texts.

Usage: python3 extract-persian.py

Output: list of Persian texts that need translation
"""
import re, glob, json, os

# Read current translations
I18N_FILE = 'public/assets/velora-i18n.js'
if os.path.exists(I18N_FILE):
    with open(I18N_FILE, 'r', encoding='utf-8') as f:
        content = f.read()
    # Extract existing translations
    existing = set(re.findall(r"'([^']*[\u0600-\u06FF][^']*)'\s*:", content))
else:
    existing = set()

# Scan all HTML files
persian_texts = set()
html_files = glob.glob('**/*.html', recursive=True)

for filepath in html_files:
    # Skip blog en pages
    if filepath.startswith('en/') or '/en/' in filepath:
        continue
    if '404' in filepath:
        continue
    
    with open(filepath, 'r', encoding='utf-8') as f:
        html = f.read()
    
    # Remove script and style blocks
    clean = re.sub(r'<script[^>]*>.*?</script>', '', html, flags=re.DOTALL)
    clean = re.sub(r'<style[^>]*>.*?</style>', '', clean, flags=re.DOTALL)
    
    # Find Persian text in HTML content
    matches = re.findall(r'>([^<]*[\u0600-\u06FF][^<]*)<', clean)
    for m in matches:
        text = m.strip()
        if text and len(text) > 1 and len(text) < 200:
            persian_texts.add(text)

# Find untranslated texts
untranslated = sorted(persian_texts - existing)

print(f"{'='*60}")
print(f"VELORA i18n — Translation Status Report")
print(f"{'='*60}")
print(f"Total Persian texts found:  {len(persian_texts)}")
print(f"Already translated:         {len(existing)}")
print(f"Need translation:           {len(untranslated)}")
print(f"{'='*60}")

if untranslated:
    print(f"\n📝 Texts that need translation:\n")
    for i, text in enumerate(untranslated, 1):
        print(f"  {i:3d}. '{text}'")
    
    print(f"\n{'='*60}")
    print(f"\n💡 To add translations, edit: {I18N_FILE}")
    print(f"   Add entries like: 'persian text': 'english text',\n")
    
    # Save to file for easy copy-paste
    with open('/tmp/untranslated.txt', 'w', encoding='utf-8') as f:
        for text in untranslated:
            f.write(f"'{text}': '',\n")
    print(f"📄 Saved to: /tmp/untranslated.txt (copy-paste ready)")
else:
    print("\n✅ All Persian texts are translated!")
