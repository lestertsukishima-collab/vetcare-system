@props(['feedbackId', 'description' => 'Circular photo preview'])

<div wire:ignore>
    <p id="{{ $feedbackId }}" class="profile-photo-feedback" data-photo-feedback role="status" aria-live="polite"></p>
    <div class="profile-photo-crop" data-photo-crop hidden>
        <canvas data-photo-canvas width="512" height="512" role="img" aria-label="{{ $description }}"></canvas>
        <div class="profile-photo-controls">
            <label>Zoom <input data-photo-zoom type="range" min="1" max="3" step="0.05" value="1" /></label>
            <label>Horizontal position <input data-photo-x type="range" min="0" max="100" step="1" value="50" /></label>
            <label>Vertical position <input data-photo-y type="range" min="0" max="100" step="1" value="50" /></label>
            <div class="profile-photo-actions">
                <button type="button" class="profile-photo-save" data-photo-save>Save photo</button>
                <button type="button" class="profile-photo-cancel" data-photo-cancel>Cancel</button>
            </div>
        </div>
    </div>
</div>
