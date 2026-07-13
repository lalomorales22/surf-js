# HANDOFF — Penguin Point 2

State of the world as of 2026-07-12 (v3 session), plus the roadmap for the next session. Read this before touching `index.php`.

## Where things stand

The v2 overhaul (shore sim, post-FX, skinned surfer + IK/ragdoll, manual takeoffs, momentum physics, barrels, radar) shipped earlier; a 28-agent adversarial review confirmed and fixed 14 findings, and headless Chrome boots clean.

The **v3 session** then delivered the whole "what's next" roadmap:

1. **True sitting + character realism.** The sit state now plants the pelvis on the deck (`placeCharOnBoard(_v3b.set(0,-0.75,-0.10), 0,0,0, 0.85)` — the root origin is at the *feet*, so the y offset is ≈ −(pelvis height − deck contact)), sinks the board (`boardFollowSurface(dt, P.yaw, 0.20, -0.14)`) so the nose third rides visibly out of the water, and `poseSit` is a real straddle (hips −1.12 x / ∓0.52 z, knees 1.28, shins hanging in the water) with slow leg-dangle and pooled `RINGS` foot ripples on a `P.sitRip` timer. The suit gained muscle-shaped ring tables (calves, forearm/biceps bulges, waist pinch, chest/shoulder taper), the head gained jaw/nose/ears/neck-bridge meshes, and the suit shader gained a neoprene normal/roughness micro-noise.
2. **Board quiver + riders.** `BOARDS` table (short/fun/long) with physics hooks wired into the state machine: `paddle` (prone speed), `catchK` (fraction of celerity to catch; SPACE-pop uses `catchK+0.02`), `grip`, `turn` (prone yaw + `tan(lean*0.85*turn)` carve), `pump`, `pearl` (LATE-airdrop risk multiplier, clamp 0.3–0.85), `stance` (foot-lock spread). `buildBoard(kind)` rebuilds geometry (length/width/thickness scales, 3 fins vs single log fin, deck colors; shared `PENG_TEX` decal survives rebuilds). `RIDERS` presets (kai/mara) feed `buildRig(key)` → clears `J/BONES/BI/headMeshes`, rebuilds the skeleton with preset clav/shoulder/hip offsets, recreates `SKEL`, recolors `MAT.*`, rebuilds the suit with preset radii (`shR/hipR/chestR/waistR`). Mara: narrower shoulders, wider hips, ponytail on an extra `hairTip2` spring bone (guarded with `if (J.hairTip2)` — it only exists for her), plum suit + coral accent. **Bone names and limb lengths are identical across presets** so the entire pose/IK/ragdoll system is untouched. Start-screen selector cards (click or 1/2/3 + R), persisted in `localStorage` (`pp2.board`, `pp2.rider`); selection is only reachable while `!started`, which is what keeps the bind-pose capture safe.
3. **Waves that read as forming barrels.** `FACEK = 0.62` front-face compression (was 0.45) shared by JS *and* GLSL via `f6()`; `faceLen(s)` helper replaced every inline face-length; shoal exponent 0.25→0.33. `OCEAN_FRAG`: `Nmac` (pre-perturbation macro normal) gates a **forming-wall** block — shore-facing slopes darken/saturate to green glass, vertical streak noise draws up the face, a crest band glows under the lip, and the backlit SSS is boosted by `wall`; lip foam weight dropped 0.62→0.50 so shape reads before white. `lipCurlAt`/`lipCurl`: pocket width 8+amp·4, amp-scaled throw (`thr = 1.25 + 0.40*sstep(1.5,2.8,amp)`), and a **pre-barrel lean** (`preK = sstep(0.22,0.90,bf) * 0.22 * envX * outside`) that pitches the whole unbroken crest forward via `max(pocketK, preK)` — the same droop math, so the GLSL twin is line-for-line.
4. **HUD.** `predictHollow(s)` runs the shoal/ratio math at the outer bar (z=−85) → purple **HOLLOW** tag on wave cards, purple crest tint + per-crest ETA labels on the radar.

Verified live this session: full catch→pop→ride loop on the new steeper faces (autopilot rode multiple waves), both riders × boards rebuild correctly, sit reads from all angles, HOLLOW/radar work, console clean. A 19-agent adversarial review over the v3 diff confirmed 9 findings (13 raw), all fixed: tube-scoring zone now derives from the shared `pocketW(s)` (it had drifted from the widened visual pocket), rebuilt board materials get `setEnvIntensity(boardG, 0.55)`, selection is idempotent + guarded against key-repeat and browser chords (Cmd/Ctrl+R was flipping the persisted rider!), the log is carried near-horizontal (nose-down carry speared the sand at len 1.32), `sitK` decays in every non-sit state (it could freeze through duck/wipe and re-dunk the rider), duck entry waits for the seat blend, the camera look-target follows `sitK` instead of snapping per-state, and hollow wave cards wrap instead of overflowing.

## Rules that keep this codebase sane

1. **One file.** `index.php` is the entire deliverable. Assets are procedural; new features must be too.
2. **JS↔GLSL parity.** `terrainY / waterH / swellHeightAt / bore* / swashFilm / lipCurlAt` exist twice: once in JS, once in the `GLSL_SHARED` template (constants injected via `f6()`, e.g. `FACEK`). Change one → change both, or physics and pixels disagree.
3. **GLSL trap #1:** never `#include <tonemapping_pars_fragment>` / `<colorspace_pars_fragment>` in the custom shaders — r160 auto-prepends them (redefinition = black screen). Only the application chunks at the end.
4. **GLSL trap #2:** no `pow(x, 2.0)` with possibly-negative `x` (square by hand), no `smoothstep(a, b, …)` with `a > b` (use the custom `sstep`, which is symmetric).
5. **PHP layer is frozen.** Endpoints, CSRF, rate limit, CSP: don't touch.
6. **Zero per-frame allocations** in the loop — module-scope scratch vectors (`_v3`, `_ik*`, etc.), pooled rings/spray, throttled HUD strings.
7. **Rig rebuilds only happen pre-game.** `buildRig`/`buildBoard` assume `charRoot` is unposed at the origin (bind matrices are captured at rest). If you ever expose mid-game switching, re-capture `SKEL` carefully.
8. **Keep every intermediate state runnable** and check after each edit:
   ```
   php -l index.php
   awk '/<script type="module"/{f=1;next} f&&/<\/script>/{f=0} f' index.php > /tmp/game.mjs && node --check /tmp/game.mjs
   ```

## Debug quick reference

- `window.PP_DBG()` → `{state, pos, yaw, spd, board, rider, swells, bores, sw:[…], limbs:{hip/knee/ankle/shoulder world positions}, boardY, waterY, _p, _c, _sw, _spawn}`
  - `_p` — live player: `_p.pos.set(x,0,z)` teleports, `_p.state='sit'` forces a state.
  - `_c` — live camera: `_c.yaw/_c.pitch/_c.dist` for scripted camera work.
  - `_sw` — the LIVE swells array; `_spawn(amp0, xc, halfW, cls, dir, closeout)` spawns a swell. **Staging trick:** set `s.zc` directly to place it, `s.c = 0; s.peelRate = 0` to freeze a wall on the bar for screenshots. Caveat: a frozen wave (c=0) lets the catch check pass at zero paddle speed — staging artifact only, `c` is never 0 naturally.
- The third-person camera rides ~3m high, so even overhead walls sit near eye level; drag the camera low (pitch ≈ 0.03) to make walls tower. Keep this in mind before "the waves look small" tuning.
- Browser automation: rAF stops when the tab is hidden — the sim freezes between screenshots (each CDP screenshot pumps ≈1 frame), and frozen mid-transition poses look broken when they aren't. `visibilitychange` also auto-pauses; a page-side `setInterval` clicking `#btnResume` un-sticks scripted sessions.
- Headless smoke: `chrome --headless=new --enable-logging=stderr --virtual-time-budget=15000 <url>` and grep stderr for `CONSOLE`.
- Server for local play: `php -S 127.0.0.1:8987` from the repo root (running during this handoff).

## NEXT UP (candidates, in rough priority order)

### 1. Wave-face read at the default camera
The forming-wall shading + pre-barrel lean are in, but the default third-person camera looks *down* at the sea, which flattens everything. Ideas: dip the camera toward water level while a card-called set is inside ~40m (ease `CAM.pitch` floor down), or a dedicated "line-up cam" toggle. Also consider strengthening `wall*0.55` → 0.65 and widening the crest band once judged in live play.

### 2. Barrel interior
Tube riding works mechanically (3× score, spit, reverb), but visually the curtain is the thrown lip only. A cheap win: while `P.tube > 0`, overdraw a curved translucent sheet (or crank `uwPass`-style shading + darken sky contribution) so being in the barrel LOOKS enclosed. Parity-safe: pure fragment-side, no physics.

### 3. Board/rider extras
- Board art variants (colorways per rider accent), wax texture on decks.
- Leash: a simple verlet strand from ankle to tail (points already exist in RAG-style math) — big believability win in wipeouts, cheap.
- A third rider preset is now ~20 lines (RIDERS entry + maybe a hair style).

### 4. Smaller follow-ups
- Session stats → leaderboard payload (best barrel/face). Needs a PHP migration — PHP layer is frozen, so decide deliberately first.
- Radar: takeoff-pocket ETA countdown, wave-height number on hover.
- The wipeout→beached transition can leave one odd eased frame when the player watches; consider a brief crouch-recover pose on `wipe→ground`.
