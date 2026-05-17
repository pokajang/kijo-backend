<footer class="pdf-footer">
    <div class="footer-left">Page <span class="page-counter"></span></div>
    <div class="footer-right">
        Computer generated on: {{ $generatedDate ?? '' }}
        by: {{ $generatedByCode ?? '' }} ({{ $generatedById ?? 'Unknown' }})
    </div>
</footer>
