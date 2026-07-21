@php
    $whoTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_who_title', 'Who is AMIOSH?')
        : 'Who is AMIOSH?';

    $whoBody = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_who_body',
            'Established in 2010, AMIOSH is a Malaysian provider of occupational safety, health and environmental services, offering integrated compliance, training, assessment and support solutions.'
        )
        : 'Established in 2010, AMIOSH is a Malaysian provider of occupational safety, health and environmental services, offering integrated compliance, training, assessment and support solutions.';

    $oshcTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_osh_consultancy', 'OSH Consultancy')
        : 'OSH Consultancy';

    $oshcList = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_osh_consultancy_list',
            'Compliance audits, Risk advisory, OSH professional outsourcing'
        )
        : 'Compliance audits, Risk advisory, OSH professional outsourcing';

    $trainingTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_training', 'OSH Training')
        : 'OSH Training';

    $trainingList = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_training_list',
            'HIRARC, Working at height, Confined space, Fire safety'
        )
        : 'HIRARC, Working at height, Confined space, Fire safety';

    $isoTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_iso', 'ISO Consultancy')
        : 'ISO Consultancy';

    $isoList = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_iso_list',
            'ISO 9001, 14001, 45001 and other management systems'
        )
        : 'ISO 9001, 14001, 45001 and other management systems';

    $ihTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_ih', 'Occupational Health')
        : 'Occupational Health';

    $ihList = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_ih_list',
            'CHRA, Noise, Ergonomics, IAQ, Exposure monitoring'
        )
        : 'CHRA, Noise, Ergonomics, IAQ, Exposure monitoring';

    $infraTitle = (isset($L) && is_callable($L))
        ? $L('cross_sell_infrastructure', 'Infrastructure')
        : 'Infrastructure';

    $infraList = (isset($L) && is_callable($L))
        ? $L(
            'cross_sell_infra_list',
            'Civil works, Building maintenance, Roads, Landscaping'
        )
        : 'Civil works, Building maintenance, Roads, Landscaping';

    $crossSellCtaPrefix = (isset($L) && is_callable($L))
        ? $L('cross_sell_cta', 'Explore our services at')
        : 'Explore our services at';

    $servicesHeading = (isset($L) && is_callable($L))
        ? $L('cross_sell_services_title', 'Our Services')
        : 'Our Services';

    $splitItems = static fn(?string $value): array => array_filter(
        array_map('trim', preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY))
    );

    $serviceLine = static fn(array $items): string => trim(implode(', ', $items) . (count($items) > 0 ? ', and more' : ' and more'));

    $serviceItems = [
        ['title' => $oshcTitle, 'items' => $splitItems($oshcList)],
        ['title' => $trainingTitle, 'items' => $splitItems($trainingList)],
        ['title' => $isoTitle, 'items' => $splitItems($isoList)],
        ['title' => $ihTitle, 'items' => $splitItems($ihList)],
        ['title' => $infraTitle, 'items' => $splitItems($infraList)],
    ];

    $serviceLinks = [
        ['label' => 'amiosh.com', 'url' => 'https://amiosh.com'],
        ['label' => 'training.amiosh.com', 'url' => 'https://training.amiosh.com'],
        ['label' => 'health.amiosh.com', 'url' => 'https://health.amiosh.com'],
        ['label' => 'iso.amiosh.com', 'url' => 'https://iso.amiosh.com'],
        ['label' => 'infra.amiosh.com', 'url' => 'https://infra.amiosh.com'],
    ];
@endphp

<style>
    .quote-cross-sell {
        --card-bg: #fcfdff;
        --card-border: #d9e2ec;
        --card-text: #0f172a;
        --card-subtle: #475569;
        --card-title: #0f172a;
        --card-muted: #334155;
        --card-accent: #003c00;
        --card-accent-bg: #f0fff0;
        --card-accent-border: #c8f0c8;

        margin: 0 0 3mm 0;
        padding: 1.8mm 2mm;
        border: 0.6px solid var(--card-border);
        border-radius: 1.6mm;
        background: var(--card-bg);
        box-shadow: 0 0.8px 0 rgba(15, 23, 42, 0.06);
        font-size: 8.6pt;
        line-height: 1.35;
        color: var(--card-text);
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .quote-cross-sell p {
        margin: 0 0 0.8mm 0;
    }

    .quote-cross-sell .section-title {
        margin: 0 0 0.7mm 0;
        padding: 0;
        font-size: 9.2pt;
        font-weight: 700;
        color: var(--card-title);
        text-transform: none;
    }

    .quote-cross-sell .service-grid {
        margin: 0.8mm 0 0.85mm 0;
    }

    .quote-cross-sell .section-divider {
        margin: 0.75mm 0 0.85mm 0;
        border-top: 0.45px solid var(--card-border);
        opacity: 0.85;
    }

    .quote-cross-sell .service-item {
        margin: 0 0 0.55mm 0;
        line-height: 1.3;
        font-size: 8.7pt;
    }

    .quote-cross-sell .service-title {
        display: inline;
        font-weight: 700;
        color: var(--card-title);
    }

    .quote-cross-sell .cta {
        margin: 0 0 0.2mm 0;
        padding: 0;
        font-size: 9.2pt;
        font-weight: 700;
        color: var(--card-muted);
        word-break: break-word;
    }

    .quote-cross-sell .cta-links {
        margin: 1.4mm 0 0 0;
    }

    .quote-cross-sell .cta-link {
        display: inline-block;
        font-size: 8.4pt;
        text-decoration: none;
        color: var(--card-accent);
        font-weight: 700;
        border: 0.6px solid var(--card-accent-border);
        border-radius: 1.2mm;
        padding: 0.7mm 1mm;
        background: var(--card-accent-bg);
        margin: 0 1.2mm 0.9mm 0;
        white-space: nowrap;
    }

    .quote-cross-sell a {
        color: inherit;
        text-decoration: none;
    }

</style>

<div class="quote-cross-sell">
    <p class="section-title">{{ $whoTitle }}</p>

    <p>{{ $whoBody }}</p>

    <div class="section-divider"></div>

    <p class="section-title">{{ $servicesHeading }}</p>

    <div class="service-grid">
        @foreach($serviceItems as $service)
            <div class="service-item">
                <span class="service-title">{{ $service['title'] }}:</span>
                {{ $serviceLine($service['items']) }}
            </div>
        @endforeach
    </div>

    <div class="section-divider"></div>

    <p class="cta">
        {{ $crossSellCtaPrefix }}
    </p>
    <div class="cta-links">
        @foreach($serviceLinks as $serviceLink)
            <a class="cta-link" href="{{ $serviceLink['url'] }}" target="_blank" rel="noopener noreferrer">
                {{ $serviceLink['label'] }}
            </a>
        @endforeach
    </div>
</div>
