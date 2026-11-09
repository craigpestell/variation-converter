#!/usr/bin/env bash
# Turns a recorded clip (.webm) into the files the website uses: an MP4, a GIF and a poster frame.
#   .wordpress-org/clips/make.sh /tmp/clips/combine-coffee.webm site/images [POSTER_SECONDS, default 17]
# The first second is cut: it holds the page loading.
set -euo pipefail
src=$1
out=$2
at=${3:-17}
name=$(basename "$src" .webm)
mkdir -p "$out"
ffmpeg -v error -y -ss 1 -i "$src" -c:v libx264 -preset slow -crf 26 -pix_fmt yuv420p -movflags +faststart -an "$out/$name.mp4"
ffmpeg -v error -y -ss 1 -i "$src" -vf "fps=8,scale=772:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=64:stats_mode=diff[p];[b][p]paletteuse=dither=none:diff_mode=rectangle" "$out/$name.gif"
ffmpeg -v error -y -ss "$at" -i "$src" -frames:v 1 -q:v 4 "$out/$name.jpg"
ls -l "$out/$name".*
# The wordpress.org listing shows the GIF as its first screenshot.
if [ "$name" = combine-coffee ]; then cp "$out/$name.gif" "$(dirname "$0")/../screenshot-1.gif"; fi
