# 🏄 surf-js

**A single-file, open-world surf sim.** One `index.php` — PHP + SQLite leaderboard on the back, a full three.js ocean on the front. No build step, no assets, no node_modules. Drop it on shared hosting or a Raspberry Pi and paddle out at **Penguin Point**. 🐧

```
git clone https://github.com/lalomorales22/surf-js
cd surf-js
php -S localhost:8000
# open http://localhost:8000 and paddle out
```

<p align="center"><i>Everything you see — waves, sand, surfer, sounds — is generated procedurally at runtime.</i></p>

## What's in the water

- **Real-feel waves** — analytic swell model with wave classes from 1–2ft to 8–10ft double-overhead faces, rare **BOMB SETS** every few minutes, lefts / rights / A-frames / closeouts, and celerity, steepness, and consequence that scale with size. Approaching sets visibly **stand up and lean forward** before they throw: the shore-facing wall darkens to green glass, streaks as water draws up the face, and the whole unbroken crest pitches toward the beach as it hits the bar.
- **A live character menu** — the start screen shows the actual selected procedural rider holding the selected board, not a placeholder avatar. Kai and Mara have distinct silhouettes, faces, suit cuts, and hair, and all six rider/board combinations update live and persist between sessions.
- **Pick your quiver** — three boards with real physics trade-offs: the 5'10" shortboard (quick rails, hard paddling, late-drop friendly), the 7'2" funboard (balanced), and the 9'1" log (glides into anything early, but slow to turn and loves to pearl). On land, the board is tucked under the arm with hand-to-rail IK instead of floating beside the rider.
- **Visible wave faces and barrels** — crest highlights, trough drawdown, darker standing faces, breaking-lip foam, and pooled overhanging water shells make an incoming set readable from the lineup. Hollow waves open into a rendered tube with an interior surface, mouth, exit light, vignette, and tube-aware chase camera. Stall into the pocket, track the tube (time in the barrel scores 3×), and get spat out with the mist.
- **Manual takeoffs** — no auto-catch. Read the lineup radar, sit in the zone, turn shoreward, paddle until the face takes you, then hit SPACE. Graded takeoffs: early bogs, sweet ones fly, late ones airdrop with a real pearl risk.
- **Momentum surfing** — your velocity lives on the moving face: gravity pulls you down-slope, rails carve with a grip limit (overpush and you slide), and pumps must be timed to the face. The rider lands side-on in a compressed stance with both feet IK-planted to the deck, then crouches deeper under pump and carve load; snaps / cutbacks / floaters are graded with speed-scaled spray.
- **A living shoreline** — dedicated swash simulation: uprush/backwash sheets with lace foam and bubble decay, backwash colliding with the next bore, sediment stirred into the drain, and wet sand that darkens, turns glossy, and mirrors the sky and the surfer before drying out.
- **Consequences** — overhead wipeouts mean 5–9 second two-stage hold-downs, ragdoll physics with per-limb water drag, and a second wave that can reset the tumble.
- **A rider who actually sits** — waiting in the lineup means straddling the board for real: tail sunk, nose up, shins in the water kicking slow ripples while the head tracks incoming sets.
- **Full post-FX chain** — bloom, god rays, SMAA, film grade, underwater pass — in three hot-swappable quality tiers, tuned for 60fps at medium on an M1 MacBook Air.
- **Procedural audio** — ocean bed, per-class break booms, spray hiss, barrel-interior reverb, bomb-set rumble. Zero audio files.
- **Local leaderboard** — self-provisioning SQLite, CSRF-guarded saves, rate-limited, top-10 board on <kbd>L</kbd>.

## Controls

| Key | On sand | On the board | Riding |
|---|---|---|---|
| <kbd>W A S D</kbd> | move | paddle / turn | pump / rail left / stall / rail right |
| <kbd>SHIFT</kbd> | run | duck dive | kick out |
| <kbd>SPACE</kbd> | hop on board | **pop up** (time it!) | — |
| <kbd>E</kbd> | — | sit up / lie down | — |
| <kbd>C</kbd> | camera 1st ↔ 3rd | ← | ← |
| <kbd>F</kbd> | fullscreen | ← | ← |
| <kbd>L</kbd> / <kbd>M</kbd> / <kbd>P</kbd> | leaderboard / mute / pause | ← | ← |
| drag / wheel | look / zoom | ← | ← |

**Start screen:** pick a rider and a board (click, or <kbd>1</kbd>/<kbd>2</kbd>/<kbd>3</kbd> for the board and <kbd>R</kbd> to switch rider).

**HUD:** wave-select cards (bottom-left) call each set — face height, LEFT/RIGHT/A-FRAME/CLOSEOUT, a purple **HOLLOW** tag when a wave will barrel over the bar, and seconds out. The **lineup radar** (bottom-right) shows every crest with its ETA, hollow waves in purple, where it's breaking (white), and the takeoff pockets (pulsing dots).

## Requirements

- PHP 7.4+ with `pdo_sqlite` (any shared host, or `php -S` locally)
- A WebGL2 browser — Chrome or Safari on desktop recommended
- Internet access for the pinned three.js r160 CDN modules (the game itself ships no assets)

Served statically (no PHP)? The game still runs — the leaderboard just marks itself offline.

## Architecture (one file, on purpose)

```
index.php
├─ PHP: sqlite provisioning (./data, web-access denied), CSRF, rate limit,
│       ?action=leaderboard / ?action=save_ride, CSP + security headers
├─ CSS: sun-bleached poster UI system
└─ ES module (~3k lines):
   config → shared wave/swash math (authored in JS, mirrored into GLSL — one
   source of truth for physics AND rendering) → post-fx tiers → sky + env maps →
   wet sand → terrain → refraction/depth prepass → ocean + forming-wall shading +
   crest ribbons + pooled barrel shells → swash strip → GPU spray → swell/bore lifecycle → rider presets →
   24-bone skinned surfer → pose library → IK / secondary motion / ragdoll →
   board quiver (physics table) → state machine → camera → input →
   procedural audio → HUD/radar/cards → leaderboard client → main loop
```

The core invariant: `h(x, z, t)` and friends are written once in JavaScript and injected into GLSL with the same constants. The board you ride and the wave you see are the same equation.

## Deploying

Upload `index.php`. That's it. The `data/` directory (SQLite + deny rules) creates itself on first save. Keep the CSP header intact — it's part of the security posture (nonce'd scripts/styles, pinned CDN, no inline handlers).
