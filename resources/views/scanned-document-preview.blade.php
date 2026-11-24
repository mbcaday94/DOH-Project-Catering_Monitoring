<button class="record-trigger" type="button" data-open-dialog="{{ $previewId }}" aria-haspopup="dialog">{{ $filename }}</button>
<dialog class="record-dialog document-preview" id="{{ $previewId }}" aria-labelledby="{{ $previewId }}-title">
    <div class="record-dialog-header">
        <h2 id="{{ $previewId }}-title">{{ $filename }}</h2>
        <form method="dialog">
            <button type="submit" autofocus>Close</button>
        </form>
    </div>
    <iframe class="document-preview-frame" title="Scanned document preview" data-preview-src="{{ $previewUrl }}"></iframe>
    <div class="document-preview-actions">
        <a href="{{ $downloadUrl }}">Download</a>
    </div>
</dialog>
