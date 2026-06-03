#!/bin/bash
base="https://hinyerevan.ru/api"
urls=(
  "photos/11180?lang=ru&translate=main"
  "photos/11180?lang=hy"
  "photos/11180?lang=ru"
  "photos/11180/comments?lang=ru"
  "photos/11180/comments?lang=hy"
  "ratings?lang=ru"
  "ratings?lang=hy"
  "photos?lang=ru&per_page=12"
  "photos?lang=hy&per_page=12"
  "facebook/page?lang=ru"
)
for u in "${urls[@]}"; do
  printf '%-45s ' "$u"
  curl -s -o /dev/null -w 'total=%{time_total}s ttfb=%{time_starttransfer}s\n' "$base/$u"
done
