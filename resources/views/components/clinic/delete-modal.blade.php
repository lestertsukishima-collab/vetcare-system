<div class="modal" id="clinic-delete-modal" tabindex="-1" aria-labelledby="clinic-delete-title" aria-describedby="clinic-delete-description" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="clinic-delete-title">Delete this record?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="clinic-delete-description">
                <p>You're about to permanently delete <strong class="modal-record-name" data-delete-name></strong>.</p>
                <p>This cannot be undone. Records linked to clinic history will be retained.</p>
                <div class="alert alert-danger" role="alert" data-delete-error hidden>We couldn't complete the request. Check your connection and try again.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep record</button>
                <button type="button" class="btn btn-danger" data-delete-confirm>Delete record</button>
            </div>
        </div>
    </div>
</div>
