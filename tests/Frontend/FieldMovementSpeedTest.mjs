import test from 'node:test';
import assert from 'node:assert/strict';
import { movementSpeedMultiplier } from '../../public/js/field/input.js';

const movement = {
    dash_multiplier: 1.8,
    spectator_multiplier: 8,
};

test('player movement keeps the existing walk and dash multipliers', () => {
    assert.equal(movementSpeedMultiplier(movement, false, false), 1);
    assert.equal(movementSpeedMultiplier(movement, false, true), 1.8);
});

test('admin spectator moves faster and can toggle an extra-fast speed', () => {
    assert.equal(movementSpeedMultiplier(movement, true, false), 8);
    assert.equal(movementSpeedMultiplier(movement, true, true), 14.4);
});

test('older payloads retain the previous spectator speed fallback', () => {
    assert.equal(movementSpeedMultiplier({ dash_multiplier: 1.8 }, true, false), 4);
});
