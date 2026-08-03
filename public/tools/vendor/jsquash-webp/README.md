# Vendored jSquash WebP encoder

- Package: `@jsquash/webp`
- Version: `1.5.0`
- Source: <https://github.com/jamsinclair/jSquash/tree/main/packages/webp>
- Purpose: browser-side lossless WebP encoding for `sprite-splitter.html`

Only the encoder files required at runtime are included. `encode.js` has one local-only change: the `wasm-feature-detect` import points to the bundled `wasm-feature-detect.js` file instead of a package-manager module name.

Licenses are retained in `LICENSE` and `codec/LICENSE.codec.md`.
