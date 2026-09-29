import test from 'node:test';
import assert from 'node:assert/strict';
import { rockOverlayAlpha, treeOverlayAlpha } from '../../public/js/field/terrain-art.js';
import { terrainRockUnderlay, terrainTreeUnderlay } from '../../public/js/field/tiles-draw.js';

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

test('tree tiles inherit nearby settlement ground before falling back to their natural biome', () => {
    const settlementWorld = {
        tileAt: (tx, ty) => tx === 12 && ty === 34 ? 'T' : 'e',
    };
    assert.equal(terrainTreeUnderlay(settlementWorld, 12, 34), 'e');

    const forestWorld = {
        tileAt: () => 'T',
        gen: {
            naturalCell: () => ({ tile: 'T', biome: 1 }),
            planeOfTile: () => 'land',
            groundFor: () => 'G',
        },
    };
    assert.equal(terrainTreeUnderlay(forestWorld, 12, 34), 'G');
});

test('tree overlay removes square corners while retaining canopy and trunk details', () => {
    assert.equal(treeOverlayAlpha(90, 150, 55, 255, 0.02, 0.02), 0);
    assert.ok(treeOverlayAlpha(32, 88, 31, 255, 0.5, 0.35) > 240);
    assert.ok(treeOverlayAlpha(105, 70, 38, 255, 0.5, 0.78) > 240);
    assert.equal(treeOverlayAlpha(90, 150, 55, 255, 0.95, 0.9), 0);
});
