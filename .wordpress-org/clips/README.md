# Demo clips

Short screen recordings for the plugin website, made with Playwright on the wp-harness site seeded with the Live Preview demo store, so anyone can re-record them after a UI change.

| Clip | Shows |
|---|---|
| `combine-coffee.mjs` | Ticking the House Blend Coffee 340g and 1kg groups on the Combine tab, Combine into one product, renaming the attributes to Weight and Grind, shortening "Espresso Grind" to "Espresso", swapping so Grind comes first, dragging the 340g bags to the top, Create, then the variable product in the shop with each dropdown shown open before Espresso and 1kg are picked, so the gallery switches to that picture (main image shrunk so the thumbnails show). |

## Record

```sh
harness/wp-harness up --fresh --no-seed
sed "s#^require '/wordpress/wp-load.php';##" .wordpress-org/blueprints/demo-store.php > harness/demo-store.tmp.php
harness/wp-harness eval harness/demo-store.tmp.php && rm harness/demo-store.tmp.php
harness/wp-harness snapshot
harness/wp-harness browse .wordpress-org/clips/combine-coffee.mjs /tmp/clips
.wordpress-org/clips/make.sh /tmp/clips/combine-coffee.webm site/images
```

Run `harness/wp-harness reset` before each take: the clip combines the coffee, so a second take on the same database finds nothing to combine.

The recording is 1100 x 700 with the admin menu folded and a drawn pointer (video has none). `make.sh` writes an MP4 (the website plays it), a 772px GIF and a poster frame. The coffee GIF is also copied to `.wordpress-org/screenshot-1.gif`, the first screenshot on the wordpress.org listing (screenshots may be GIFs, and `bin/svn-release.sh` copies them). Needs `ffmpeg`.
