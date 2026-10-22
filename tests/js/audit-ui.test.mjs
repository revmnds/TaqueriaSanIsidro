import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

const page = (name) => readFileSync(new URL(`../../resources/views/pages/${name}.blade.php`, import.meta.url), 'utf8');

test('el arqueo conserva el importe manual al abrir y guarda cero al quitar el último billete', () => {
    const source = page('corte');
    const helper = source.slice(source.indexOf('{{-- Arqueo por billetes:'));
    const data = helper.match(/x-data="([\s\S]*?)"/)[1];
    const effect = helper.match(/x-effect="([\s\S]*?)"/)[1];
    const input = helper.match(/@input="([\s\S]*?)"/)[1];
    const state = Function(`return (${data})`)();
    const wire = { counted: '175' };
    const sync = () => Function('$wire', `with (this) { ${effect} }`).call(state, wire);
    state.open = true;
    sync();
    assert.equal(wire.counted, '175');
    state.bills[500] = '1';
    Function(`with (this) { ${input} }`).call(state);
    sync();
    assert.equal(wire.counted, '500');
    state.bills[500] = '';
    sync();
    assert.equal(wire.counted, '0');
});

test('el botón de sonido activa en el primer toque y después permite apagar y prender', async () => {
    const source = page('cocina');
    const data = source.match(/x-data="([\s\S]*?)"\s+x-init=/)[1];
    const button = source.match(/<button[^>]+@click="toggleSound\(\)"[^>]*>/)[0];
    const storage = new Map();
    const localStorage = { getItem: (key) => storage.get(key), setItem: (key, value) => storage.set(key, value) };
    class AudioContext {
        state = 'suspended';
        async resume() { this.state = 'running'; }
    }
    const state = Function('localStorage', 'AudioContext', `return (${data})`)(localStorage, AudioContext);
    state.$nextTick = () => {};
    const tap = async () => {
        if (!button.includes('@pointerdown.stop')) state.unlock();
        await Promise.resolve();
        state.toggleSound();
        await Promise.resolve();
    };
    await tap();
    assert.equal(state.sound, true);
    assert.equal(state.audioReady, true);
    assert.equal(storage.get('cocina:sonido'), 'si');
    await tap();
    assert.equal(state.sound, false);
    await tap();
    assert.equal(state.sound, true);
});
