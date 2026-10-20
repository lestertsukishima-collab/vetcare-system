export const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

export function photoFileError(file) {
    if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        return 'Choose a JPG, PNG, or WebP image.';
    }
    return file.size > MAX_PHOTO_BYTES ? 'The photo must be 2 MB or smaller.' : null;
}

// Crop coordinates are in source pixels; both the preview and upload use this square.
export function squareCrop(width, height, zoom = 1, horizontal = 50, vertical = 50) {
    const clamp = (value, min, max) => Math.min(max, Math.max(min, Number(value) || min));
    const size = Math.min(width, height) / clamp(zoom, 1, 3);
    return {
        x: (width - size) * clamp(horizontal, 0, 100) / 100,
        y: (height - size) * clamp(vertical, 0, 100) / 100,
        size,
    };
}

export function drawPhotoCrop(canvas, image, zoom, horizontal, vertical) {
    const crop = squareCrop(image.naturalWidth, image.naturalHeight, zoom, horizontal, vertical);
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Image preview is unavailable. Please try again.');
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, crop.x, crop.y, crop.size, crop.size, 0, 0, canvas.width, canvas.height);
}

export async function loadPhotoImage(file, window) {
    const url = window.URL.createObjectURL(file);
    try {
        return await new Promise((resolve, reject) => {
            const image = new window.Image();
            image.onload = () => image.naturalWidth && image.naturalHeight
                ? resolve(image) : reject(new Error('This image could not be opened. Choose another photo.'));
            image.onerror = () => reject(new Error('This image could not be opened. Choose another photo.'));
            image.src = url;
        });
    } finally {
        window.URL.revokeObjectURL(url);
    }
}

export function installProfilePhotos({ window, document }) {
    if (window.vetcareProfilePhotos) return;
    window.vetcareProfilePhotos = true;
    const editors = new Map();
    const field = (root, name) => root.querySelector(`[data-photo-${name}]`);
    const photoLabel = root => root.dataset?.photoLabel || 'Profile photo';
    const stateFor = root => {
        if (!editors.has(root)) editors.set(root, { image: null, busy: false, revision: 0 });
        return editors.get(root);
    };
    const current = (root, state, revision) => root.isConnected && state.revision === revision;
    const feedback = (root, message = '', error = false) => {
        const element = field(root, 'feedback');
        if (!element) return;
        element.textContent = message;
        element.dataset.error = String(error);
    };
    const busy = (root, state, value) => {
        state.busy = value;
        root.setAttribute('aria-busy', String(value));
        root.querySelectorAll('input, button').forEach(control => { control.disabled = value; });
    };
    const reset = (root, state) => {
        state.image = null;
        field(root, 'crop').hidden = true;
        field(root, 'file').value = '';
    };
    const draw = (root, state) => drawPhotoCrop(
        field(root, 'canvas'), state.image,
        field(root, 'zoom').value, field(root, 'x').value, field(root, 'y').value,
    );

    const choose = async (root, file) => {
        const state = stateFor(root);
        if (state.busy || !file) return;
        const revision = ++state.revision;
        reset(root, state);
        const error = photoFileError(file);
        if (error) { feedback(root, error, true); return; }
        busy(root, state, true);
        feedback(root, 'Preparing your photo…');
        try {
            const image = await loadPhotoImage(file, window);
            if (!current(root, state, revision)) return;
            state.image = image;
            field(root, 'zoom').value = '1';
            field(root, 'x').value = field(root, 'y').value = '50';
            draw(root, state);
            field(root, 'crop').hidden = false;
            feedback(root, 'Adjust the circular preview, then save your photo.');
        } catch (error) {
            if (current(root, state, revision)) feedback(root, error.message, true);
        } finally {
            if (current(root, state, revision)) {
                busy(root, state, false);
                if (state.image) field(root, 'save').focus();
            }
        }
    };

    const save = async root => {
        const state = stateFor(root);
        if (state.busy || !state.image) return;
        const revision = state.revision;
        busy(root, state, true);
        feedback(root, 'Uploading your photo…');
        try {
            const canvas = field(root, 'canvas');
            const blob = await new Promise((resolve, reject) => canvas.toBlob(
                value => value ? resolve(value) : reject(new Error('The photo could not be prepared. Please try again.')),
                'image/png',
            ));
            if (!current(root, state, revision)) return;
            const filename = root.dataset?.photoKind === 'pet' ? 'pet-photo.png' : 'profile-photo.png';
            const file = new window.File([blob], filename, { type: 'image/png' });
            const error = photoFileError(file);
            if (error) throw new Error(error);
            const wire = window.Livewire?.find(root.getAttribute('wire:id'));
            if (!wire) throw new Error('The upload is unavailable. Refresh the page and try again.');
            await new Promise((resolve, reject) => wire.$upload('photo', file, resolve,
                () => reject(new Error('The upload failed. Check your connection and try again.')),
                event => {
                    if (current(root, state, revision)) feedback(root, `Uploading your photo… ${event.detail.progress}%`);
                },
                () => reject(new Error('The upload was cancelled. Please try again.')),
            ));
            if (!current(root, state, revision)) return;
            const saved = await wire.$call('savePhoto');
            if (!current(root, state, revision)) return;
            if (saved === true) {
                reset(root, state);
                feedback(root, `${photoLabel(root)} saved.`);
            } else {
                feedback(root, 'The photo could not be saved. Check the message below and try again.', true);
            }
        } catch (error) {
            if (current(root, state, revision)) feedback(root, error.message || 'The photo could not be saved. Please try again.', true);
        } finally {
            if (current(root, state, revision)) {
                busy(root, state, false);
                if (!state.image) field(root, 'file').focus();
            }
        }
    };

    const remove = async root => {
        const state = stateFor(root);
        if (state.busy) return;
        const revision = ++state.revision;
        busy(root, state, true);
        feedback(root, 'Removing your photo…');
        try {
            await window.Livewire.find(root.getAttribute('wire:id')).$call('removePhoto');
            if (!current(root, state, revision)) return;
            reset(root, state);
            feedback(root, `${photoLabel(root)} removed.`);
        } catch {
            if (current(root, state, revision)) feedback(root, 'The photo could not be removed. Please try again.', true);
        } finally {
            if (current(root, state, revision)) {
                busy(root, state, false);
                field(root, 'file').focus();
            }
        }
    };

    document.addEventListener('change', event => {
        if (!event.target.matches('[data-photo-file]')) return;
        const root = event.target.closest('[data-profile-photo-editor]');
        if (root) void choose(root, event.target.files?.[0]);
    });
    document.addEventListener('input', event => {
        if (!event.target.matches('[data-photo-zoom], [data-photo-x], [data-photo-y]')) return;
        const root = event.target.closest('[data-profile-photo-editor]');
        const state = root && stateFor(root);
        if (state?.image && !state.busy) draw(root, state);
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-photo-save], [data-photo-cancel], [data-photo-remove]');
        const root = button?.closest('[data-profile-photo-editor]');
        if (!root) return;
        const state = stateFor(root);
        if (state.busy) return;
        if (button.matches('[data-photo-save]')) void save(root);
        if (button.matches('[data-photo-remove]')) void remove(root);
        if (button.matches('[data-photo-cancel]')) {
            state.revision++;
            reset(root, state);
            feedback(root, 'Photo selection cancelled.');
            field(root, 'file').focus();
        }
    });
    window.addEventListener('profile-photo-updated', event => {
        const { userId, url, initials } = event.detail;
        document.querySelectorAll('[data-account-avatar]').forEach(avatar => {
            if (avatar.dataset.photoOwner !== String(userId)) return;
            const element = document.createElement(url ? 'img' : 'span');
            if (url) { element.src = url; element.alt = ''; element.width = element.height = 96; }
            else element.textContent = initials;
            avatar.replaceChildren(element);
        });
    });
    window.addEventListener('pet-photo-updated', event => {
        const { petId, url } = event.detail;
        document.querySelectorAll('[data-pet-avatar]').forEach(avatar => {
            if (avatar.dataset.photoPet !== String(petId)) return;
            const content = avatar.querySelector('[data-pet-avatar-content]');
            if (!content) return;
            if (url) {
                const image = document.createElement('img');
                image.src = url;
                image.alt = '';
                image.width = image.height = 96;
                content.replaceChildren(image);
            } else {
                const fallback = avatar.querySelector('[data-pet-photo-fallback]');
                if (fallback) content.replaceChildren(fallback.content.cloneNode(true));
            }
        });
    });
    const cleanup = () => {
        for (const [root, state] of editors) {
            state.revision++;
            if (state.busy) window.Livewire?.find(root.getAttribute('wire:id'))?.$cancelUpload('photo');
        }
        editors.clear();
    };
    document.addEventListener('livewire:navigating', cleanup);
    window.addEventListener('pagehide', cleanup);
}
