import assert from 'node:assert/strict';
import test from 'node:test';
import { MAX_PHOTO_BYTES, photoFileError, squareCrop, drawPhotoCrop, loadPhotoImage, installProfilePhotos } from '../../resources/js/profile-photos.js';

test('only supported images up to and including 2 MB can enter the cropper', () => {
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
        assert.equal(photoFileError({ type, size: MAX_PHOTO_BYTES }), null);
        assert.match(photoFileError({ type, size: MAX_PHOTO_BYTES + 1 }), /2 MB/);
    }
    for (const type of ['image/svg+xml', 'image/gif', 'text/html']) {
        assert.match(photoFileError({ type, size: 100 }), /JPG, PNG, or WebP/);
    }
});

test('portrait and landscape images use an adjustable square entirely inside the original', () => {
    assert.deepEqual(squareCrop(1200, 800), { x: 200, y: 0, size: 800 });
    assert.deepEqual(squareCrop(800, 1200), { x: 0, y: 200, size: 800 });
    assert.deepEqual(squareCrop(1200, 800, 2), { x: 400, y: 200, size: 400 });
    assert.deepEqual(squareCrop(1200, 800, 2, 100, 0), { x: 800, y: 0, size: 400 });
    for (const [width, height] of [[1200, 800], [800, 1200]]) {
        const { x, y, size } = squareCrop(width, height, 99, -20, 300);
        assert.ok(x >= 0 && y >= 0 && x + size <= width && y + size <= height);
    }
});

test('the square preview and saved canvas use the same crop without stretching', () => {
    const calls = [];
    const canvas = { width: 512, height: 512, getContext: () => ({ clearRect() {}, drawImage: (...args) => calls.push(args) }) };
    const image = { naturalWidth: 1200, naturalHeight: 800 };
    drawPhotoCrop(canvas, image, 2, 100, 0);
    assert.deepEqual(calls, [[image, 800, 0, 400, 400, 0, 0, 512, 512]]);
});

test('temporary object URLs are released after successful and failed image decoding', async () => {
    for (const failure of [false, true]) {
        const revoked = [];
        const window = {
            URL: { createObjectURL: () => 'blob:photo', revokeObjectURL: url => revoked.push(url) },
            Image: class {
                naturalWidth = 800;
                naturalHeight = 600;
                set src(value) { queueMicrotask(() => failure ? this.onerror() : this.onload()); }
            },
        };
        if (failure) await assert.rejects(loadPhotoImage({}, window), /could not be opened/);
        else assert.equal((await loadPhotoImage({}, window)).naturalWidth, 800);
        assert.deepEqual(revoked, ['blob:photo']);
    }
});

const tick = () => new Promise(resolve => setImmediate(resolve));

function browser({ saveResult = true, uploadFailure = false, deferred = false, kind = 'profile' } = {}) {
    const listeners = () => {
        const handlers = new Map();
        return {
            addEventListener(type, fn) { handlers.set(type, [...(handlers.get(type) ?? []), fn]); },
            fire(type, event = {}) { for (const fn of handlers.get(type) ?? []) fn(event); },
        };
    };
    const fields = new Map();
    let root;
    const element = name => ({
        name, value: '', hidden: false, disabled: false, dataset: {}, textContent: '',
        matches(selector) { return selector.split(',').some(part => part.trim() === `[data-photo-${name}]`); },
        closest(selector) { return selector === '[data-profile-photo-editor]' ? root : this.matches(selector) ? this : null; },
        focus() { this.focused = !this.disabled; },
    });
    for (const name of ['file', 'feedback', 'crop', 'canvas', 'zoom', 'x', 'y', 'save', 'cancel', 'remove']) fields.set(name, element(name));
    fields.get('crop').hidden = true;
    fields.get('zoom').value = '1';
    fields.get('x').value = fields.get('y').value = '50';
    const drawn = [];
    Object.assign(fields.get('canvas'), {
        width: 512, height: 512,
        getContext: () => ({ clearRect() {}, drawImage: (...args) => drawn.push(args) }),
        toBlob: callback => callback(new Blob(['square-photo'], { type: 'image/png' })),
    });
    const attributes = new Map();
    root = {
        isConnected: true,
        dataset: { photoKind: kind, photoLabel: kind === 'pet' ? 'Pet photo' : 'Profile photo' },
        querySelector(selector) { return fields.get(selector.match(/data-photo-([\w-]+)/)[1]); },
        querySelectorAll() { return [...fields.values()]; },
        getAttribute: () => 'photo-component', setAttribute: (key, value) => attributes.set(key, value),
    };
    const document = { ...listeners(), querySelectorAll: () => [], createElement: tag => ({ tag }) };
    const calls = [];
    let finishUpload, finishSave;
    const wire = {
        $upload(property, file, finish, error, progress, cancel) {
            calls.push({ property, file });
            finishUpload = () => finish();
            this.$cancelUpload = () => cancel();
            progress({ detail: { progress: 50 } });
            if (uploadFailure) error();
            else if (!deferred) finish();
        },
        $call(method) {
            calls.push(method);
            if (method === 'removePhoto') return Promise.resolve();
            return deferred ? new Promise(resolve => { finishSave = () => resolve(saveResult); }) : Promise.resolve(saveResult);
        },
        $cancelUpload() {},
    };
    const window = {
        ...listeners(), File,
        Livewire: { find: () => wire },
        URL: { createObjectURL: () => 'blob:photo', revokeObjectURL() {} },
        Image: class {
            naturalWidth = 1200;
            naturalHeight = 800;
            set src(value) { queueMicrotask(() => this.onload()); }
        },
    };
    installProfilePhotos({ window, document });
    return {
        root, document, window, fields, calls, drawn, attributes,
        async choose(file = { type: 'image/jpeg', size: 1024 }) {
            fields.get('file').files = [file];
            document.fire('change', { target: fields.get('file') });
            await tick();
        },
        click(name) { document.fire('click', { target: fields.get(name) }); },
        finishUpload: () => finishUpload(), finishSave: () => finishSave(),
    };
}

test('selecting and cancelling a photo leaves the stored photo untouched', async () => {
    const ui = browser();
    await ui.choose();
    assert.equal(ui.fields.get('crop').hidden, false);
    assert.equal(ui.fields.get('save').focused, true);
    assert.equal(ui.drawn.length, 1);
    assert.deepEqual(ui.calls, []);
    ui.click('cancel');
    assert.equal(ui.fields.get('crop').hidden, true);
    assert.deepEqual(ui.calls, []);
});

test('oversized originals are rejected before decoding or upload', async () => {
    const ui = browser();
    await ui.choose({ type: 'image/png', size: MAX_PHOTO_BYTES + 1 });
    assert.match(ui.fields.get('feedback').textContent, /2 MB/);
    assert.equal(ui.drawn.length, 0);
    assert.deepEqual(ui.calls, []);
});

test('saving waits for upload and server confirmation and prevents duplicate requests', async () => {
    const ui = browser({ deferred: true });
    await ui.choose();
    ui.click('save');
    await tick();
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.calls[0].file.type, 'image/png');
    assert.equal(ui.calls[0].file.name, 'profile-photo.png');
    assert.equal(ui.fields.get('save').disabled, true);
    ui.click('save');
    ui.click('remove');
    assert.equal(ui.calls.length, 1);
    ui.finishUpload();
    await tick();
    assert.equal(ui.calls[1], 'savePhoto');
    assert.equal(ui.fields.get('crop').hidden, false);
    assert.equal(ui.fields.get('save').disabled, true);
    ui.finishSave();
    await tick();
    assert.equal(ui.fields.get('crop').hidden, true);
    assert.equal(ui.fields.get('save').disabled, false);
    assert.equal(ui.fields.get('file').focused, true);
    assert.match(ui.fields.get('feedback').textContent, /saved/);
});

test('upload or validation failure preserves the crop for retry and releases controls', async () => {
    for (const options of [{ uploadFailure: true }, { saveResult: null }]) {
        const ui = browser(options);
        await ui.choose();
        ui.click('save');
        await tick();
        assert.equal(ui.fields.get('crop').hidden, false);
        assert.equal(ui.fields.get('save').disabled, false);
        assert.equal(ui.fields.get('feedback').dataset.error, 'true');
    }
});

test('navigation during upload prevents a stale save and late UI changes', async () => {
    const ui = browser({ deferred: true });
    await ui.choose();
    ui.click('save');
    await tick();
    const message = ui.fields.get('feedback').textContent;
    ui.root.isConnected = false;
    ui.document.fire('livewire:navigating');
    ui.finishUpload();
    await tick();
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.fields.get('feedback').textContent, message);
});

test('a saved or removed photo updates only the matching account avatars', () => {
    const ui = browser();
    const avatars = ['1', '1', '2'].map(photoOwner => ({ dataset: { photoOwner }, replaceChildren(element) { this.child = element; } }));
    ui.document.querySelectorAll = () => avatars;
    ui.window.fire('profile-photo-updated', { detail: { userId: '1', url: '/account/profile-photo/new.png', initials: 'LT' } });
    assert.equal(avatars[0].child.src, '/account/profile-photo/new.png');
    assert.equal(avatars[1].child.src, avatars[0].child.src);
    assert.equal(avatars[2].child, undefined);
    ui.window.fire('profile-photo-updated', { detail: { userId: '1', url: null, initials: 'LT' } });
    assert.equal(avatars[0].child.tag, 'span');
    assert.equal(avatars[0].child.textContent, 'LT');
});

test('pet photos use the same square upload flow and show pet-specific feedback', async () => {
    const ui = browser({ kind: 'pet' });
    await ui.choose();
    ui.click('save');
    await tick();
    assert.equal(ui.calls[0].file.name, 'pet-photo.png');
    assert.equal(ui.fields.get('feedback').textContent, 'Pet photo saved.');
    ui.click('remove');
    await tick();
    assert.equal(ui.fields.get('feedback').textContent, 'Pet photo removed.');
});

test('updating or removing a pet photo affects only that pet and keeps a reusable paw fallback', () => {
    const ui = browser();
    const avatars = ['1', '1', '2'].map(photoPet => {
        const content = { replaceChildren(child) { this.child = child; } };
        const fallback = { content: { cloneNode: () => ({ tag: 'paw' }) } };
        return { dataset: { photoPet }, content,
            querySelector: selector => selector === '[data-pet-avatar-content]' ? content : fallback,
        };
    });
    ui.document.querySelectorAll = () => avatars;
    ui.window.fire('pet-photo-updated', { detail: { petId: '1', url: '/clinic/pets/1/photo/new.png' } });
    assert.equal(avatars[0].content.child.src, '/clinic/pets/1/photo/new.png');
    assert.equal(avatars[1].content.child.src, avatars[0].content.child.src);
    assert.equal(avatars[2].content.child, undefined);
    ui.window.fire('pet-photo-updated', { detail: { petId: '1', url: null } });
    assert.equal(avatars[0].content.child.tag, 'paw');
    ui.window.fire('pet-photo-updated', { detail: { petId: '1', url: '/clinic/pets/1/photo/replacement.png' } });
    assert.equal(avatars[0].content.child.src, '/clinic/pets/1/photo/replacement.png');
});

test('cancelling one pet crop leaves another open pet editor intact', async () => {
    const first = browser({ kind: 'pet' });
    const second = browser({ kind: 'pet' });
    await first.choose();
    second.fields.get('file').files = [{ type: 'image/jpeg', size: 1024 }];
    first.document.fire('change', { target: second.fields.get('file') });
    await tick();
    first.click('cancel');
    assert.equal(first.fields.get('crop').hidden, true);
    assert.equal(second.fields.get('crop').hidden, false);
    assert.deepEqual(first.calls, []);
});
