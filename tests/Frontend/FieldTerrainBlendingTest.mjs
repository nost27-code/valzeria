import test from 'node:test';
import assert from 'node:assert/strict';
import { rockOverlayAlpha } from '../../public/js/field/terrain-art.js';
import { terrainRockUnderlay } from '../../public/js/field/tiles-draw.js';

test('rock tiles inherit the local biome ground instead of the grass atlas background', () => {
    const world = {
        gen: {
            naturalCell: () => ({ tile: 'R', biome: 10 }),
            planeOfTile: () => 'land',
            groundFor: (biome, plane) => biome === 10 && plane === 'land' ? 'K' : 'G',
        },
    };

    assert.equal(terrainRockUnderlay(world, 12, 34), 'K');
    assert.equal(terrainRockUnderlay({}, 12, 34), 'H');
});

test('rock overlay removes saturated grass while retaining neutral rock and shadow pixels', () => {
    assert.equal(rockOverlayAlpha(72, 145, 48, 255, 0.5, 0.55), 0);
    assert.ok(rockOverlayAlpha(145, 142, 136, 255, 0.5, 0.55) > 240);
    assert.ok(rockOverlayAlpha(45, 42, 39, 255, 0.5, 0.7) > 240);
    assert.equal(rockOverlayAlpha(145, 142, 136, 255, 0.01, 0.01), 0);
});
