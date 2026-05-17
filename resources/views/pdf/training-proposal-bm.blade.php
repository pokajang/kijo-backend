{{-- BM variant entrypoint. Proposal content is selected from BM templates; static label refinement lives here. --}}
@php($pdfLanguage = 'ms-MY')
@include('pdf.training-proposal', get_defined_vars())
