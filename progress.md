Original prompt: Review surf-js, clean it up, show the actual riders on the menu, improve male/female character readability and surfboard carry pose, upgrade in-water waves so they visibly form rideable faces and barrels, and produce a complete app-upgrade plan after reviewing the repository documentation.

## 2026-07-13 review

- Read `README.md` and `HANDOFF.md`; the project is a single-file PHP + Three.js surf game with procedural visuals and a strict JS/GLSL wave-parity invariant.
- Reviewed menu presentation, rider rig/build presets, ground carry pose, wave geometry/shading/barrel presentation, camera, physics, automated hooks, backend read path, and runtime behavior.
- Confirmed the menu displays emoji rather than live character models.
- Confirmed rider presets are technically distinct, but the differences are too subtle at the gameplay camera distance.
- Confirmed `carryBoard()` positions the board independently and has no carrying-arm IK/contact target, producing the floating-board look.
- Confirmed forming/barreling wave states are represented in simulation/radar data but do not read as a steep face or enclosed tube from the player camera.
- `php -l`, extracted JavaScript `node --check`, live boot, PHP leaderboard GET, rider/board selection, and console checks passed.
- Added `UPGRADE_PLAN.md` with a seven-phase implementation and verification roadmap.

## Next implementation slice

- Added `render_game_to_text`, deterministic `advanceTime` under `?test=1`, and fullscreen `F` support.
- Refactored the frame loop into explicit simulation and render functions without changing normal clock-driven play.
- Replaced the opaque start screen with a transparent two-column layout around a live Three.js rider/board showcase.
- Strengthened Kai/Mara body, face, hair, and wetsuit silhouette parameters.
- Rebuilt ground carry orientation and added carrying-arm IK so the hand stays planted on the board.
- Added pooled procedural breaking-lip/barrel shells driven by the existing swell pocket data.
- Strengthened face shading and added an automatic low lineup camera when a readable set approaches.
- Added deterministic `forming` and `barrel` visual staging hooks for the regression loop.
- Increased shared face compression and added a shared JS/GLSL shore-facing trough so wave faces gain real crest-to-trough height without breaking physics/render parity.
- Added a bright procedural lip line, denser barrel surface, tube-aware chase camera, interior vignette, and a deterministic `ridebarrel` scenario.
- Added separate outer/inner barrel surfaces, an opening arc, foam lip beads, allocation-free two-axis normals, and a genuinely overhead cross-section.
- Added pooled crest highlights driven by the shared `waterH` + `lipCurlAt` functions so approaching waves have a readable breaking line from water level.
- Brightened procedural board materials and turned the menu rider to a three-quarter angle so the under-arm board reads in the real preview.
- Removed the menu backdrop blur that caused intermittent headless compositor tiling.
- Fixed live-preview rig rebuilding to bind every new skeleton from an identity rest frame, then restore the menu transform.
- Fixed the menu-to-game transition to discard preview foot locks and spring history before capturing contacts at the real beach spawn.
- Visually verified all six Kai/Mara × short/fun/long menu combinations after repeated rebuilds; no detached meshes or bind drift.
- Verified a real-time (non-test-mode) repeated rider switch → Mara/log → start transition, with an intact ground pose and under-arm contact.
- Verified deterministic forming and ride-barrel scenes: visible swell/crest, active overhead shell, `tube:true`, synchronized timer/score state, and no console errors.
- Verified keyboard selection, menu fullscreen, pause/resume, ground movement, leaderboard GET, PHP syntax, extracted-module syntax, and clean browser console.
- Core visual implementation and documentation pass complete.

## 2026-07-13 surf-stance follow-up

- User reported that the riding pose reads as floating above the board instead of a planted surfing stance.
- Root cause: the character root was placed `0.12m` above the board while the hip-to-deck distance exceeded the two-leg IK reach, so the ankle targets could not be reached.
- Lowered the riding root into a dynamic crouch, rotated the stance fully side-on, strengthened the bent-knee/open-shoulder pose, and hard-aligned both feet to the board after leg IK.
- Added `PP_STAGE('ride')` for an unobscured open-face stance regression alongside `ridebarrel`.
- Final stance tuning lowers the neutral hips another 8 cm and gives pumping a deeper compression range; the pop-up now lands at the same height, avoiding a one-frame rise into the ride.
- Visually verified neutral ride, active pump compression, barrel riding, Kai/fun, Mara/long, and pop-up-to-surf landing screenshots. Feet remain on the deck and the knees stay visibly flexed in every checked state.
- Final checks: required web-game Playwright client passed, `render_game_to_text()` stayed synchronized, barrel staging reported `tube:true`, PHP syntax and `git diff --check` passed, and browser console/page errors were empty.

## Remaining ideas (not blockers)

- A future polish pass could add regular/goofy stance selection and board-specific foot-width presets; the current stance is intentionally consistent across the full quiver.
