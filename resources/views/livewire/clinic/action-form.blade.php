<div class="live-form">
    @if($recordId)
        @include('clinic.edit-form')
    @else
        @include('clinic.create-form')
    @endif
    @if($saved)<p class="saved-message" role="status">Changes saved successfully.</p>@endif
    @if($errors->any())
        <div class="notice error alert alert-danger" role="alert" tabindex="-1" data-validation-errors><strong>Please check your details.</strong>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
</div>
