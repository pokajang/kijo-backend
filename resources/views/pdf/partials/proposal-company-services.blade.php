@php
    $label = static fn(string $key, string $fallback): string => isset($L) && is_callable($L)
        ? $L($key, $fallback)
        : $fallback;

    $serviceItems = [
        [
            'title' => $label('proposal_osh_consultancy', 'OSH Consultancy'),
            'description' => $label('proposal_osh_consultancy_list', 'Compliance audits, risk advisory and OSH professional outsourcing'),
        ],
        [
            'title' => $label('proposal_osh_training', 'OSH Training'),
            'description' => $label('proposal_osh_training_list', 'HIRARC, working at height, confined space and fire safety'),
        ],
        [
            'title' => $label('proposal_iso_consultancy', 'ISO Consultancy'),
            'description' => $label('proposal_iso_consultancy_list', 'ISO 9001, 14001, 45001 and other management systems'),
        ],
        [
            'title' => $label('proposal_occupational_health', 'Occupational Health'),
            'description' => $label('proposal_occupational_health_list', 'CHRA, noise, ergonomics, IAQ and exposure monitoring'),
        ],
        [
            'title' => $label('proposal_infrastructure', 'Infrastructure'),
            'description' => $label('proposal_infrastructure_list', 'Civil works, building maintenance, roads and landscaping'),
        ],
    ];
@endphp

<style>
    .proposal-company-services {
        margin: 0 0 4mm;
        padding: 3.2mm 3.6mm;
        border: 0.6px solid #d9e2ec;
        border-radius: 1.6mm;
        background: #fcfdff;
        color: #1f2937;
        font-size: 9pt;
        line-height: 1.35;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .proposal-company-services .company-services-heading {
        margin: 0 0 1mm;
        color: #003c00;
        font-size: 10.2pt;
        font-weight: 700;
        line-height: 1.25;
    }

    .proposal-company-services p {
        margin: 0;
    }

    .proposal-company-services .company-services-copy {
        color: #334155;
    }

    .proposal-company-services .services-heading {
        margin-top: 1.8mm;
        color: #0f172a;
        font-size: 9.4pt;
        font-weight: 700;
    }

    .proposal-company-services .service-list {
        margin-top: 0.8mm;
    }

    .proposal-company-services .service-item {
        margin: 0 0 0.45mm;
    }

    .proposal-company-services .service-title {
        color: #0f172a;
        font-weight: 700;
    }

    .proposal-company-services .services-link {
        margin-top: 1.2mm;
        color: #475569;
    }

    .proposal-company-services a {
        color: #003c00;
        font-weight: 700;
        text-decoration: underline;
    }
</style>

<section class="proposal-company-services" aria-labelledby="proposal-company-services-title">
    <h2 id="proposal-company-services-title" class="company-services-heading">
        {{ $label('proposal_company_services_title', 'About AMIOSH') }}
    </h2>
    <p class="company-services-copy">
        {{ $label('proposal_company_services_body', 'Established in 2010, AMIOSH is a Malaysian provider of occupational safety, health and environmental services. We offer integrated compliance, training, assessment and support solutions.') }}
    </p>

    <p class="services-heading">{{ $label('proposal_company_services_heading', 'Our Integrated Services') }}</p>

    <div class="service-list">
        @foreach($serviceItems as $service)
            <p class="service-item">
                <span class="service-title">{{ $service['title'] }}:</span>
                {{ $service['description'] }}
            </p>
        @endforeach
    </div>

    <p class="services-link">
        {{ $label('proposal_company_services_cta', 'Learn more about AMIOSH services at') }}
        <a href="https://amiosh.com" target="_blank" rel="noopener noreferrer">amiosh.com</a>.
    </p>
</section>
