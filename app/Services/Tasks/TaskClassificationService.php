<?php

namespace App\Services\Tasks;

class TaskClassificationService
{
    private const FUZZY_MATCH_THRESHOLD = 0.28;

    private const FUZZY_HIGH_CONFIDENCE_THRESHOLD = 0.18;

    private const TYPO_CORRECTION_MIN_LENGTH = 4;

    private const TYPO_PROTECTED_TOKENS = [
        'api' => true,
        'hr' => true,
        'id' => true,
        'po' => true,
        'sop' => true,
        'ui' => true,
    ];

    private const TASK_CATEGORY_DEFINITIONS = [
        'non_work' => [
            'label' => 'Non-rated / Not graded',
            'effort_score' => 0.0,
        ],
        'unclear_unrated' => [
            'label' => 'Unclear / Not graded',
            'effort_score' => 0.0,
        ],
        'administrative' => [
            'label' => 'Administrative',
            'effort_score' => 1.0,
        ],
        'pending_waiting' => [
            'label' => 'Pending / Waiting',
            'effort_score' => 0.5,
        ],
        'coordination_follow_up' => [
            'label' => 'Coordination / Follow-up',
            'effort_score' => 2.0,
        ],
        'real_effort' => [
            'label' => 'Real Effort',
            'effort_score' => 3.0,
        ],
        'deep_work' => [
            'label' => 'Deep / Complex Work',
            'effort_score' => 5.0,
        ],
        'critical_escalation' => [
            'label' => 'Critical / Escalation',
            'effort_score' => 4.0,
        ],
        'uncategorised' => [
            'label' => 'General Task',
            'effort_score' => 1.0,
        ],
    ];

    private const WORK_TYPE_DEFINITIONS = [
        'clerical_admin' => 'Clerical / Admin',
        'coordination_followup' => 'Coordination / Follow-up',
        'commercial_sales' => 'Commercial / Sales',
        'operations_logistics' => 'Operations / Logistics',
        'technical_specialist' => 'Technical / Specialist',
        'software_it' => 'Software / IT',
        'finance_hr' => 'Finance / HR',
        'management_strategy' => 'Management / Strategy',
        'training_delivery' => 'Training / Delivery',
        'creative_content' => 'Creative / Content',
        'non_work' => 'Non-work',
        'unclear' => 'Unclear',
    ];

    private const WORK_TYPE_RULES = [
        'software_it' => [
            'feature',
            'bug',
            'api',
            'database',
            'deploy',
            'deployment',
            'migration',
            'automation',
            'frontend',
            'backend',
            'server',
            'domain',
            'kijo',
            'crud',
            'login issue',
            'production outage',
            'system outage',
            'landing page',
            'dashboard',
            'portal',
        ],
        'technical_specialist' => [
            'hse',
            'ih',
            'chra',
            'hirarc',
            'hirarac',
            'osh',
            'osha',
            'dosh',
            'niosh',
            'risk assessment',
            'compliance',
            'technical report',
            'site inspection',
            'site audit',
            'audit response',
            'method statement',
            'cem',
            'safety',
            'incident',
            'accident',
            'pematuhan',
            'penilaian risiko',
        ],
        'finance_hr' => [
            'payroll',
            'payslip',
            'gaji',
            'claim',
            'invoice',
            'reconcile',
            'account',
            'month end',
            'cash flow',
            'budget',
            'audit preparation',
            'recruit',
            'candidate',
            'interview',
            'onboarding',
            'appraisal',
            'salary review',
            'disciplinary',
            'hr',
            'hrdc',
            'tuntutan',
        ],
        'management_strategy' => [
            'kpi',
            'framework',
            'strategy',
            'resource planning',
            'workforce planning',
            'manpower planning',
            'restructuring',
            'policy',
            'procedure',
            'handbook',
            'board report',
            'management signoff',
            'rancang',
            'strategi',
        ],
        'commercial_sales' => [
            'proposal',
            'quotation',
            'quote',
            'tender',
            'rfq',
            'rfp',
            'rfi',
            'client pitch',
            'pitching',
            'pricing',
            'costing',
            'estimate',
            'scope of work',
            'contract',
            'sales',
            'marketing',
            'campaign',
            'google ads',
            'sebutharga',
            'cadangan',
        ],
        'training_delivery' => [
            'training',
            'trainer',
            'workshop',
            'class',
            'course',
            'session',
            'teach',
            'awareness talk',
            'participant',
            'latihan',
            'kursus',
            'mengajar',
        ],
        'creative_content' => [
            'video',
            'infographic',
            'poster',
            'storyboard',
            'script',
            'slides',
            'presentation',
            'content',
            'copywriting',
            'editing',
            'shoot',
            'skrip',
        ],
        'operations_logistics' => [
            'delivery',
            'collect',
            'pickup',
            'pick up',
            'equipment',
            'gas detector',
            'scba',
            'first aid',
            'travel',
            'outstation',
            'hotel',
            'flight',
            'site visit',
            'business trip',
            'mobilization',
            'logistics',
            'lawatan tapak',
            'luar kawasan',
        ],
        'coordination_followup' => [
            'follow up',
            'followup',
            'remind',
            'chase',
            'arrange',
            'coordinate',
            'schedule',
            'liaise',
            'confirm',
            'meeting',
            'discussion',
            'call',
            'whatsapp',
            'hubungi',
            'atur',
            'ingatkan',
        ],
        'clerical_admin' => [
            'data entry',
            'update record',
            'upload',
            'print',
            'scan',
            'file',
            'filing',
            'email document',
            'submit form',
            'register',
            'attendance sheet',
            'certificate',
            'purchase order',
            'po',
            'admin task',
            'office task',
            'kemaskini',
            'muat naik',
            'cetak',
            'failkan',
            'emel',
            'daftar',
            'isi borang',
        ],
    ];

    private const NON_WORK_PATTERNS = [
        'watch tv',
        'watching tv',
        'watch movie',
        'watching movie',
        'watch netflix',
        'scroll tiktok',
        'scroll facebook',
        'social media',
        'play game',
        'playing game',
        'main game',
        'makan nasi',
        'lunch',
        'breakfast',
        'dinner',
        'coffee break',
        'smoke break',
        'personal errand',
        'shopping',
        'buy groceries',
        'personal appointment',
        'sleep',
        'sleeping',
        'tidur',
        'baring',
        'nap',
        'holiday',
        'cuti',
        'ambil cuti',
        'cuti tahunan',
        'pergi bercuti',
        'lepak',
        'makan',
        'pergi makan',
        'keluar makan',
        'sarapan',
        'makan tengah hari',
        'makan malam',
        'minum kopi',
        'minum teh',
        'rehat',
        'rehat sekejap',
        'hisap rokok',
        'main telefon',
        'main hp',
        'main mobile legend',
        'main mobile legends',
        'main pubg',
        'main ml',
        'main ps',
        'main playstation',
        'tengok movie',
        'tengok drama',
        'tengok tv',
        'tengok youtube',
        'tengok netflix',
        'tonton movie',
        'tonton tv',
        'tonton youtube',
        'layan netflix',
        'scroll tiktok',
        'scroll facebook',
        'beli barang',
        'shopping barang',
        'urusan peribadi',
        'hal peribadi',
        'ambil anak',
        'balik rumah',
        'sembang kosong',
        'borak kosong',
    ];

    private const NON_WORK_EXACT_PATTERNS = [
        'tapioca',
        'ubi kayu',
    ];

    private const TRASH_TOKENS = [
        'asdf' => true,
        'asdfasdf' => true,
        'qwer' => true,
        'qwerty' => true,
        'qwertyuiop' => true,
        'zxcv' => true,
        'zxcvzxcv' => true,
        'hjkl' => true,
        'lkjh' => true,
        'lkjhg' => true,
        'sdfg' => true,
        'sdfgh' => true,
    ];

    private const FALLBACK_ACTION_EXCLUDED_TERMS = [
        'book' => true,
        'call' => true,
        'do' => true,
        'make' => true,
        'run' => true,
        'travel' => true,
        'visit' => true,
        'whatsapp' => true,
    ];

    private const WORK_SIGNAL_STOP_TOKENS = [
        'and' => true,
        'as' => true,
        'for' => true,
        'from' => true,
        'in' => true,
        'new' => true,
        'of' => true,
        'out' => true,
        'task' => true,
        'the' => true,
        'to' => true,
        'with' => true,
    ];

    private const TASK_CLASSIFICATION_PATTERNS = [
        ['pattern' => 'update training record', 'category' => 'administrative'],
        ['pattern' => 'upload attendance sheet', 'category' => 'administrative'],
        ['pattern' => 'keyin payment', 'category' => 'administrative'],
        ['pattern' => 'print certificate', 'category' => 'administrative'],
        ['pattern' => 'email document to client', 'category' => 'administrative'],
        ['pattern' => 'scan file', 'category' => 'administrative'],
        ['pattern' => 'register participant', 'category' => 'administrative'],
        ['pattern' => 'check document', 'category' => 'administrative'],
        ['pattern' => 'admin task', 'category' => 'administrative'],
        ['pattern' => 'office task', 'category' => 'administrative'],
        ['pattern' => 'loa', 'category' => 'administrative'],
        ['pattern' => 'do po', 'category' => 'administrative'],
        ['pattern' => 'pay niosh', 'category' => 'administrative'],
        ['pattern' => 'payslip', 'category' => 'administrative'],
        ['pattern' => 'gaji', 'category' => 'administrative'],
        ['pattern' => 'find gift', 'category' => 'administrative'],
        ['pattern' => 'fall arrester', 'category' => 'administrative'],
        ['pattern' => 'update record', 'category' => 'administrative'],
        ['pattern' => 'update invoice record', 'category' => 'administrative'],
        ['pattern' => 'update claim record', 'category' => 'administrative'],
        ['pattern' => 'upload receipt', 'category' => 'administrative'],
        ['pattern' => 'submit claim form', 'category' => 'administrative'],
        ['pattern' => 'file purchase order', 'category' => 'administrative'],
        ['pattern' => 'data entry', 'category' => 'administrative'],
        ['pattern' => 'update employee profile', 'category' => 'administrative'],
        ['pattern' => 'upload participant list', 'category' => 'administrative'],
        ['pattern' => 'print invoice', 'category' => 'administrative'],
        ['pattern' => 'scan receipt', 'category' => 'administrative'],
        ['pattern' => 'kemaskini rekod', 'category' => 'administrative'],
        ['pattern' => 'kema kini rekod', 'category' => 'administrative'],
        ['pattern' => 'kemaskini invoice', 'category' => 'administrative'],
        ['pattern' => 'kemaskini tuntutan', 'category' => 'administrative'],
        ['pattern' => 'muat naik', 'category' => 'administrative'],
        ['pattern' => 'failkan', 'category' => 'administrative'],
        ['pattern' => 'cetak', 'category' => 'administrative'],
        ['pattern' => 'emel', 'category' => 'administrative'],
        ['pattern' => 'hantar borang', 'category' => 'administrative'],
        ['pattern' => 'daftar', 'category' => 'administrative'],
        ['pattern' => 'isi borang', 'category' => 'administrative'],

        ['pattern' => 'waiting for approval', 'category' => 'pending_waiting'],
        ['pattern' => 'waiting approval', 'category' => 'pending_waiting'],
        ['pattern' => 'pending po', 'category' => 'pending_waiting'],
        ['pattern' => 'awaiting feedback', 'category' => 'pending_waiting'],
        ['pattern' => 'awaiting response', 'category' => 'pending_waiting'],
        ['pattern' => 'waiting for hr approval', 'category' => 'pending_waiting'],
        ['pattern' => 'waiting vendor quotation', 'category' => 'pending_waiting'],
        ['pattern' => 'pending management signoff', 'category' => 'pending_waiting'],
        ['pattern' => 'awaiting finance confirmation', 'category' => 'pending_waiting'],
        ['pattern' => 'waiting trainer confirmation', 'category' => 'pending_waiting'],
        ['pattern' => 'tunggu approval', 'category' => 'pending_waiting'],
        ['pattern' => 'tunggu boss', 'category' => 'pending_waiting'],
        ['pattern' => 'tunggu client', 'category' => 'pending_waiting'],
        ['pattern' => 'tunggu feedback', 'category' => 'pending_waiting'],
        ['pattern' => 'menunggu dokumen', 'category' => 'pending_waiting'],
        ['pattern' => 'menunggu kelulusan', 'category' => 'pending_waiting'],

        ['pattern' => 'follow up client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'followup client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'fup client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'f u', 'category' => 'coordination_follow_up'],
        ['pattern' => 'arrange meeting', 'category' => 'coordination_follow_up'],
        ['pattern' => 'discussion with', 'category' => 'coordination_follow_up'],
        ['pattern' => 'discuss with', 'category' => 'coordination_follow_up'],
        ['pattern' => 'request temporary id pass', 'category' => 'coordination_follow_up'],
        ['pattern' => 'collect gas detector', 'category' => 'coordination_follow_up'],
        ['pattern' => 'pickup first aid', 'category' => 'coordination_follow_up'],
        ['pattern' => 'chase namelist', 'category' => 'coordination_follow_up'],
        ['pattern' => 'witness trd', 'category' => 'coordination_follow_up'],
        ['pattern' => 'coordinate training schedule', 'category' => 'coordination_follow_up'],
        ['pattern' => 'liaise with trainer', 'category' => 'coordination_follow_up'],
        ['pattern' => 'confirm participant attendance', 'category' => 'coordination_follow_up'],
        ['pattern' => 'arrange vendor delivery', 'category' => 'coordination_follow_up'],
        ['pattern' => 'coordinate outstation logistics', 'category' => 'coordination_follow_up'],
        ['pattern' => 'book hotel', 'category' => 'coordination_follow_up'],
        ['pattern' => 'book flight', 'category' => 'coordination_follow_up'],
        ['pattern' => 'arrange interview', 'category' => 'coordination_follow_up'],
        ['pattern' => 'schedule onboarding', 'category' => 'coordination_follow_up'],
        ['pattern' => 'coordinate payroll cutoff', 'category' => 'coordination_follow_up'],
        ['pattern' => 'liaise with supplier', 'category' => 'coordination_follow_up'],
        ['pattern' => 'confirm delivery schedule', 'category' => 'coordination_follow_up'],
        ['pattern' => 'follow up quotation', 'category' => 'coordination_follow_up'],
        ['pattern' => 'follow up invoice', 'category' => 'coordination_follow_up'],
        ['pattern' => 'atur site visit', 'category' => 'coordination_follow_up'],
        ['pattern' => 'atur lawatan tapak', 'category' => 'coordination_follow_up'],
        ['pattern' => 'atur perjalanan', 'category' => 'coordination_follow_up'],
        ['pattern' => 'atur meeting', 'category' => 'coordination_follow_up'],
        ['pattern' => 'hubungi client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'hubungi vendor', 'category' => 'coordination_follow_up'],
        ['pattern' => 'whatsapp client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'ingatkan client', 'category' => 'coordination_follow_up'],
        ['pattern' => 'remind client', 'category' => 'coordination_follow_up'],

        ['pattern' => 'create training module', 'category' => 'real_effort'],
        ['pattern' => 'quotation training', 'category' => 'real_effort'],
        ['pattern' => 'claim hrdc', 'category' => 'real_effort'],
        ['pattern' => 'rfq', 'category' => 'real_effort'],
        ['pattern' => 'rfp', 'category' => 'real_effort'],
        ['pattern' => 'rfi', 'category' => 'real_effort'],
        ['pattern' => 'frq', 'category' => 'real_effort'],
        ['pattern' => 'set up domain', 'category' => 'real_effort'],
        ['pattern' => 'attending pitching', 'category' => 'real_effort'],
        ['pattern' => 'pitching', 'category' => 'real_effort'],
        ['pattern' => 'conduct cem', 'category' => 'real_effort'],
        ['pattern' => 'osha class', 'category' => 'real_effort'],
        ['pattern' => 'awareness talk', 'category' => 'real_effort'],
        ['pattern' => 'google ads', 'category' => 'real_effort'],
        ['pattern' => 'sponsorship further study', 'category' => 'real_effort'],
        ['pattern' => 'crud coverage', 'category' => 'real_effort'],
        ['pattern' => 'editing video', 'category' => 'real_effort'],
        ['pattern' => 'editing infographic', 'category' => 'real_effort'],
        ['pattern' => 'compile video', 'category' => 'real_effort'],
        ['pattern' => 'script video', 'category' => 'real_effort'],
        ['pattern' => 'skrip video', 'category' => 'real_effort'],
        ['pattern' => 'storyboard video', 'category' => 'real_effort'],
        ['pattern' => 'shooting video', 'category' => 'real_effort'],
        ['pattern' => 'shooting lessons learned', 'category' => 'real_effort'],
        ['pattern' => 'amendment poster', 'category' => 'real_effort'],
        ['pattern' => 'advertise vacancy', 'category' => 'real_effort'],
        ['pattern' => 'prepare gantt chart', 'category' => 'real_effort'],
        ['pattern' => 'prepare osh report', 'category' => 'real_effort'],
        ['pattern' => 'prepare report', 'category' => 'real_effort'],
        ['pattern' => 'prepare proposal', 'category' => 'real_effort'],
        ['pattern' => 'create custom proposal', 'category' => 'real_effort'],
        ['pattern' => 'prepare custom proposal', 'category' => 'real_effort'],
        ['pattern' => 'draft custom proposal', 'category' => 'real_effort'],
        ['pattern' => 'write proposal from scratch', 'category' => 'real_effort'],
        ['pattern' => 'prepare proposal from scratch', 'category' => 'real_effort'],
        ['pattern' => 'create commercial proposal', 'category' => 'real_effort'],
        ['pattern' => 'prepare technical proposal', 'category' => 'real_effort'],
        ['pattern' => 'prepare scope of work', 'category' => 'real_effort'],
        ['pattern' => 'create scope of work', 'category' => 'real_effort'],
        ['pattern' => 'prepare costing sheet', 'category' => 'real_effort'],
        ['pattern' => 'prepare cost estimate', 'category' => 'real_effort'],
        ['pattern' => 'prepare price estimate', 'category' => 'real_effort'],
        ['pattern' => 'draft method statement', 'category' => 'real_effort'],
        ['pattern' => 'prepare write up', 'category' => 'real_effort'],
        ['pattern' => 'develop react dashboard', 'category' => 'deep_work'],
        ['pattern' => 'develop new feature', 'category' => 'deep_work'],
        ['pattern' => 'build new feature', 'category' => 'deep_work'],
        ['pattern' => 'implement feature', 'category' => 'deep_work'],
        ['pattern' => 'feature development', 'category' => 'deep_work'],
        ['pattern' => 'custom feature development', 'category' => 'deep_work'],
        ['pattern' => 'develop customer portal feature', 'category' => 'deep_work'],
        ['pattern' => 'develop kijo feature', 'category' => 'deep_work'],
        ['pattern' => 'build kijo module', 'category' => 'deep_work'],
        ['pattern' => 'implement kijo module', 'category' => 'deep_work'],
        ['pattern' => 'architect system', 'category' => 'deep_work'],
        ['pattern' => 'design system architecture', 'category' => 'deep_work'],
        ['pattern' => 'software architecture design', 'category' => 'deep_work'],
        ['pattern' => 'technical architecture design', 'category' => 'deep_work'],
        ['pattern' => 'refactor legacy module', 'category' => 'deep_work'],
        ['pattern' => 'refactor codebase', 'category' => 'deep_work'],
        ['pattern' => 'large refactor', 'category' => 'deep_work'],
        ['pattern' => 'optimize database performance', 'category' => 'deep_work'],
        ['pattern' => 'performance optimization', 'category' => 'deep_work'],
        ['pattern' => 'database schema redesign', 'category' => 'deep_work'],
        ['pattern' => 'data migration plan', 'category' => 'deep_work'],
        ['pattern' => 'database migration plan', 'category' => 'deep_work'],
        ['pattern' => 'system integration', 'category' => 'deep_work'],
        ['pattern' => 'api integration architecture', 'category' => 'deep_work'],
        ['pattern' => 'complex api integration', 'category' => 'deep_work'],
        ['pattern' => 'develop automation workflow', 'category' => 'deep_work'],
        ['pattern' => 'build internal tool', 'category' => 'deep_work'],
        ['pattern' => 'technical report analysis', 'category' => 'deep_work'],
        ['pattern' => 'technical analysis report', 'category' => 'deep_work'],
        ['pattern' => 'full analysis report from scratch', 'category' => 'deep_work'],
        ['pattern' => 'complex client report analysis', 'category' => 'deep_work'],
        ['pattern' => 'compliance framework', 'category' => 'deep_work'],
        ['pattern' => 'compliance framework design', 'category' => 'deep_work'],
        ['pattern' => 'management framework design', 'category' => 'deep_work'],
        ['pattern' => 'develop landing page', 'category' => 'real_effort'],
        ['pattern' => 'create landing page', 'category' => 'real_effort'],
        ['pattern' => 'build landing page', 'category' => 'real_effort'],
        ['pattern' => 'frontend development', 'category' => 'real_effort'],
        ['pattern' => 'backend development', 'category' => 'real_effort'],
        ['pattern' => 'api integration', 'category' => 'real_effort'],
        ['pattern' => 'database migration', 'category' => 'real_effort'],
        ['pattern' => 'write unit test', 'category' => 'real_effort'],
        ['pattern' => 'write regression test', 'category' => 'real_effort'],
        ['pattern' => 'test release', 'category' => 'real_effort'],
        ['pattern' => 'debug problem', 'category' => 'real_effort'],
        ['pattern' => 'debug issue', 'category' => 'real_effort'],
        ['pattern' => 'debug bug', 'category' => 'real_effort'],
        ['pattern' => 'fix login bug', 'category' => 'real_effort'],
        ['pattern' => 'fix ui bug', 'category' => 'real_effort'],
        ['pattern' => 'fix bug', 'category' => 'real_effort'],
        ['pattern' => 'bug fixing', 'category' => 'real_effort'],
        ['pattern' => 'investigate bug', 'category' => 'real_effort'],
        ['pattern' => 'troubleshoot issue', 'category' => 'real_effort'],
        ['pattern' => 'deploy release', 'category' => 'real_effort'],
        ['pattern' => 'configure server', 'category' => 'real_effort'],
        ['pattern' => 'design certificate template', 'category' => 'real_effort'],
        ['pattern' => 'review audit report', 'category' => 'real_effort'],
        ['pattern' => 'report writing', 'category' => 'real_effort'],
        ['pattern' => 'write client report', 'category' => 'real_effort'],
        ['pattern' => 'prepare client report', 'category' => 'real_effort'],
        ['pattern' => 'draft client report', 'category' => 'real_effort'],
        ['pattern' => 'prepare technical report', 'category' => 'real_effort'],
        ['pattern' => 'write technical report', 'category' => 'real_effort'],
        ['pattern' => 'prepare compliance report', 'category' => 'real_effort'],
        ['pattern' => 'troubleshoot login issue', 'category' => 'real_effort'],
        ['pattern' => 'write sop', 'category' => 'real_effort'],
        ['pattern' => 'create sop', 'category' => 'real_effort'],
        ['pattern' => 'develop sop', 'category' => 'real_effort'],
        ['pattern' => 'draft policy', 'category' => 'real_effort'],
        ['pattern' => 'write policy', 'category' => 'real_effort'],
        ['pattern' => 'write rules', 'category' => 'real_effort'],
        ['pattern' => 'write regulations', 'category' => 'real_effort'],
        ['pattern' => 'create procedure', 'category' => 'real_effort'],
        ['pattern' => 'prepare handbook', 'category' => 'real_effort'],
        ['pattern' => 'conduct training', 'category' => 'real_effort'],
        ['pattern' => 'deliver training', 'category' => 'real_effort'],
        ['pattern' => 'provide training', 'category' => 'real_effort'],
        ['pattern' => 'one day training', 'category' => 'real_effort'],
        ['pattern' => '1 day training', 'category' => 'real_effort'],
        ['pattern' => 'full day training', 'category' => 'real_effort'],
        ['pattern' => 'run workshop', 'category' => 'real_effort'],
        ['pattern' => 'prepare training material', 'category' => 'real_effort'],
        ['pattern' => 'outstation support', 'category' => 'real_effort'],
        ['pattern' => 'travel outstation', 'category' => 'real_effort'],
        ['pattern' => 'overnight site visit', 'category' => 'real_effort'],
        ['pattern' => 'business trip', 'category' => 'real_effort'],
        ['pattern' => 'site inspection', 'category' => 'real_effort'],
        ['pattern' => 'site audit', 'category' => 'real_effort'],
        ['pattern' => 'site visit report', 'category' => 'real_effort'],
        ['pattern' => 'prepare payroll', 'category' => 'real_effort'],
        ['pattern' => 'process payroll', 'category' => 'real_effort'],
        ['pattern' => 'month end closing', 'category' => 'deep_work'],
        ['pattern' => 'month end close', 'category' => 'deep_work'],
        ['pattern' => 'monthly closing', 'category' => 'deep_work'],
        ['pattern' => 'financial closing', 'category' => 'deep_work'],
        ['pattern' => 'cash flow forecast', 'category' => 'deep_work'],
        ['pattern' => 'cashflow forecast', 'category' => 'deep_work'],
        ['pattern' => 'budget planning', 'category' => 'deep_work'],
        ['pattern' => 'financial audit preparation', 'category' => 'deep_work'],
        ['pattern' => 'audit preparation', 'category' => 'deep_work'],
        ['pattern' => 'prepare board report', 'category' => 'deep_work'],
        ['pattern' => 'recruit candidate', 'category' => 'real_effort'],
        ['pattern' => 'screen candidate', 'category' => 'real_effort'],
        ['pattern' => 'conduct interview', 'category' => 'real_effort'],
        ['pattern' => 'workforce planning', 'category' => 'deep_work'],
        ['pattern' => 'manpower planning', 'category' => 'deep_work'],
        ['pattern' => 'salary review analysis', 'category' => 'deep_work'],
        ['pattern' => 'disciplinary investigation', 'category' => 'deep_work'],
        ['pattern' => 'organization restructuring', 'category' => 'deep_work'],
        ['pattern' => 'prepare appraisal', 'category' => 'real_effort'],
        ['pattern' => 'design kpi framework', 'category' => 'deep_work'],
        ['pattern' => 'department strategy plan', 'category' => 'deep_work'],
        ['pattern' => 'resource planning', 'category' => 'deep_work'],
        ['pattern' => 'risk assessment', 'category' => 'deep_work'],
        ['pattern' => 'prepare tender', 'category' => 'real_effort'],
        ['pattern' => 'review contract', 'category' => 'real_effort'],
        ['pattern' => 'prepare presentation', 'category' => 'real_effort'],
        ['pattern' => 'prepare meeting minutes', 'category' => 'real_effort'],
        ['pattern' => 'draft memo', 'category' => 'real_effort'],
        ['pattern' => 'write memo', 'category' => 'real_effort'],
        ['pattern' => 'prepare briefing note', 'category' => 'real_effort'],
        ['pattern' => 'create marketing content', 'category' => 'real_effort'],
        ['pattern' => 'plan campaign', 'category' => 'real_effort'],
        ['pattern' => 'analyse sales data', 'category' => 'real_effort'],
        ['pattern' => 'prepare financial report', 'category' => 'real_effort'],
        ['pattern' => 'reconcile accounts', 'category' => 'real_effort'],
        ['pattern' => 'reconcile supplier account', 'category' => 'real_effort'],
        ['pattern' => 'reconcile client account', 'category' => 'real_effort'],
        ['pattern' => 'reconcile bank account', 'category' => 'real_effort'],
        ['pattern' => 'process staff claim', 'category' => 'real_effort'],
        ['pattern' => 'process invoice', 'category' => 'real_effort'],
        ['pattern' => 'prepare quotation', 'category' => 'real_effort'],
        ['pattern' => 'buat modul latihan', 'category' => 'real_effort'],
        ['pattern' => 'buat modul training', 'category' => 'real_effort'],
        ['pattern' => 'sediakan proposal', 'category' => 'real_effort'],
        ['pattern' => 'sediakan modul', 'category' => 'real_effort'],
        ['pattern' => 'buat laporan', 'category' => 'real_effort'],
        ['pattern' => 'buat slides', 'category' => 'real_effort'],
        ['pattern' => 'buat sop', 'category' => 'real_effort'],
        ['pattern' => 'sediakan sop', 'category' => 'real_effort'],
        ['pattern' => 'tulis polisi', 'category' => 'real_effort'],
        ['pattern' => 'tulis peraturan', 'category' => 'real_effort'],
        ['pattern' => 'jalankan latihan', 'category' => 'real_effort'],
        ['pattern' => 'mengajar training', 'category' => 'real_effort'],
        ['pattern' => 'lawatan luar kawasan', 'category' => 'real_effort'],
        ['pattern' => 'kerja luar kawasan', 'category' => 'real_effort'],
        ['pattern' => 'lawatan tapak', 'category' => 'real_effort'],
        ['pattern' => 'analisis', 'category' => 'real_effort'],
        ['pattern' => 'rancang bajet', 'category' => 'deep_work'],
        ['pattern' => 'rancang tenaga kerja', 'category' => 'deep_work'],
        ['pattern' => 'bangunkan sistem', 'category' => 'deep_work'],
        ['pattern' => 'bangunkan ciri baru', 'category' => 'deep_work'],
        ['pattern' => 'migrasi data', 'category' => 'deep_work'],
        ['pattern' => 'penilaian risiko', 'category' => 'deep_work'],
        ['pattern' => 'semak laporan', 'category' => 'real_effort'],

        ['pattern' => 'fix production login error', 'category' => 'critical_escalation'],
        ['pattern' => 'production login outage', 'category' => 'critical_escalation'],
        ['pattern' => 'fix production bug', 'category' => 'critical_escalation'],
        ['pattern' => 'production outage', 'category' => 'critical_escalation'],
        ['pattern' => 'payment gateway down', 'category' => 'critical_escalation'],
        ['pattern' => 'security incident', 'category' => 'critical_escalation'],
        ['pattern' => 'data loss', 'category' => 'critical_escalation'],
        ['pattern' => 'urgent bug', 'category' => 'critical_escalation'],
        ['pattern' => 'resolve client complaint', 'category' => 'critical_escalation'],
        ['pattern' => 'prepare dosh audit response', 'category' => 'critical_escalation'],
        ['pattern' => 'investigate accident', 'category' => 'critical_escalation'],
        ['pattern' => 'server down', 'category' => 'critical_escalation'],
        ['pattern' => 'system outage', 'category' => 'critical_escalation'],
        ['pattern' => 'service unavailable', 'category' => 'critical_escalation'],
        ['pattern' => 'respond to compliance issue', 'category' => 'critical_escalation'],
        ['pattern' => 'compliance breach', 'category' => 'critical_escalation'],
        ['pattern' => 'non compliance issue', 'category' => 'critical_escalation'],
        ['pattern' => 'urgent issue', 'category' => 'critical_escalation'],
        ['pattern' => 'critical issue', 'category' => 'critical_escalation'],
        ['pattern' => 'client complaint', 'category' => 'critical_escalation'],
        ['pattern' => 'kemalangan', 'category' => 'critical_escalation'],
        ['pattern' => 'aduan client', 'category' => 'critical_escalation'],
        ['pattern' => 'komplen client', 'category' => 'critical_escalation'],
        ['pattern' => 'isu pematuhan', 'category' => 'critical_escalation'],
        ['pattern' => 'finding audit', 'category' => 'critical_escalation'],
        ['pattern' => 'sistem error', 'category' => 'critical_escalation'],
        ['pattern' => 'tak boleh login', 'category' => 'critical_escalation'],
    ];

    private const TASK_CLASSIFICATION_RULES = [
        [
            'category' => 'critical_escalation',
            'intent' => 'critical_escalation',
            'requiredAny' => [
                'server down',
                'tak boleh login',
                'complaint',
                'incident',
                'accident',
                'compliance issue',
                'compliance breach',
                'non compliance',
                'audit finding',
                'system error',
                'production outage',
                'production bug',
                'security breach',
                'security incident',
                'data loss',
                'service unavailable',
                'payment gateway down',
            ],
        ],
        [
            'category' => 'pending_waiting',
            'intent' => 'pending_waiting',
            'verbs' => ['waiting', 'pending', 'awaiting', 'tunggu', 'menunggu'],
            'objects' => [
                'approval',
                'feedback',
                'document',
                'po',
                'response',
                'hr',
                'manager',
                'management',
                'vendor',
                'supplier',
                'trainer',
                'accounts',
                'finance',
                'payment',
                'quotation',
                'signoff',
                'confirmation',
            ],
        ],
        [
            'category' => 'coordination_follow_up',
            'intent' => 'coordination_follow_up',
            'verbs' => [
                'follow up',
                'remind',
                'hubungi',
                'contact',
                'arrange',
                'coordinate',
                'schedule',
                'liaise',
                'confirm',
                'atur',
                'call',
                'whatsapp',
                'book',
                'discuss',
                'chase',
                'collect',
                'pickup',
                'pick up',
            ],
            'objects' => [
                'client',
                'customer',
                'po',
                'payment',
                'meeting',
                'attendance',
                'trainer',
                'schedule',
                'site',
                'visit',
                'vendor',
                'supplier',
                'delivery',
                'logistics',
                'travel',
                'hotel',
                'flight',
                'outstation',
                'interview',
                'candidate',
                'onboarding',
                'payroll',
                'cutoff',
                'quotation',
                'invoice',
                'stakeholder',
                'equipment',
                'gas detector',
                'scba',
                'first aid',
                'box',
                'namelist',
            ],
        ],
        [
            'category' => 'deep_work',
            'intent' => 'deep_work',
            'verbs' => [
                'architect',
                'design',
                'develop',
                'build',
                'implement',
                'refactor',
                'redesign',
                'migrate',
                'integrate',
                'optimize',
                'automate',
                'forecast',
                'budget',
                'plan',
                'investigate',
                'analyse',
                'analyze',
                'restructure',
                'bangunkan',
                'migrasi',
                'rancang',
            ],
            'objects' => [
                'new feature',
                'feature',
                'architecture',
                'system',
                'module',
                'codebase',
                'database',
                'data migration',
                'integration',
                'api integration',
                'complex integration',
                'automation',
                'workflow',
                'internal tool',
                'portal',
                'application',
                'platform',
                'kijo',
                'cash flow',
                'forecast',
                'budget',
                'month end',
                'audit preparation',
                'workforce',
                'manpower',
                'salary review',
                'disciplinary',
                'restructuring',
                'kpi framework',
                'strategy',
                'resource planning',
                'risk assessment',
                'compliance framework',
                'technical report analysis',
                'analysis report',
                'sistem',
                'ciri baru',
                'tenaga kerja',
                'bajet',
                'risiko',
            ],
        ],
        [
            'category' => 'real_effort',
            'intent' => 'real_effort',
            'verbs' => [
                'create',
                'prepare',
                'develop',
                'design',
                'review',
                'make',
                'do',
                'compose',
                'produce',
                'generate',
                'formulate',
                'outline',
                'troubleshoot',
                'implement',
                'build',
                'code',
                'debug',
                'fix',
                'investigate',
                'test',
                'write',
                'draft',
                'revise',
                'conduct',
                'deliver',
                'provide',
                'teach',
                'train',
                'run',
                'audit',
                'inspect',
                'visit',
                'travel',
                'pay',
                'find',
                'witness',
                'configure',
                'migrate',
                'integrate',
                'deploy',
                'document',
                'edit',
                'compile',
                'shoot',
                'advertise',
                'setup',
                'process',
                'reconcile',
                'screen',
                'recruit',
                'analyse',
                'analyze',
                'plan',
                'buat',
                'hasilkan',
                'sediakan',
                'bangunkan',
                'reka',
                'semak',
                'tulis',
                'jalankan',
                'mengajar',
            ],
            'objects' => [
                'module',
                'proposal',
                'custom proposal',
                'commercial proposal',
                'technical proposal',
                'report',
                'client report',
                'technical report',
                'compliance report',
                'quotation',
                'quote',
                'scope',
                'scope of work',
                'costing',
                'estimate',
                'price estimate',
                'pricing',
                'method statement',
                'write up',
                'write-up',
                'briefing note',
                'memo',
                'minutes',
                'meeting minutes',
                'dashboard',
                'chart',
                'template',
                'slides',
                'analysis',
                'feature',
                'bug',
                'issue',
                'problem',
                'landing page',
                'page',
                'website',
                'frontend',
                'backend',
                'api',
                'database',
                'migration',
                'release',
                'test',
                'server',
                'sop',
                'policy',
                'rules',
                'regulations',
                'handbook',
                'procedure',
                'training',
                'workshop',
                'course',
                'session',
                'material',
                'outstation',
                'travel',
                'business trip',
                'site visit',
                'site',
                'overnight',
                'audit',
                'inspection',
                'payroll',
                'recruitment',
                'candidate',
                'interview',
                'onboarding',
                'appraisal',
                'tender',
                'contract',
                'presentation',
                'marketing',
                'content',
                'campaign',
                'domain',
                'video',
                'infographic',
                'poster',
                'storyboard',
                'script',
                'vacancy',
                'pass',
                'hirarc',
                'hirarac',
                'manual',
                'workflow',
                'financial',
                'accounts',
                'account',
                'supplier account',
                'client account',
                'bank account',
                'claim',
                'invoice',
                'quotation',
                'safety',
                'google ads',
                'ads',
                'class',
                'talk',
                'gift',
                'sponsorship',
                'study',
                'crud',
                'coverage',
            ],
        ],
        [
            'category' => 'administrative',
            'intent' => 'administrative',
            'verbs' => [
                'update',
                'upload',
                'print',
                'scan',
                'check',
                'email',
                'register',
                'submit',
                'file',
                'archive',
                'kemaskini',
                'cetak',
                'emel',
                'daftar',
                'isi',
            ],
            'objects' => [
                'record',
                'attendance',
                'certificate',
                'file',
                'document',
                'form',
                'participant',
                'invoice',
                'receipt',
                'claim',
                'purchase order',
                'po',
                'profile',
                'employee',
                'list',
                'data entry',
                'timesheet',
            ],
        ],
    ];

    private const TASK_CLASSIFICATION_TOKEN_ALIASES = [
        'followup' => 'follow up',
        'fup' => 'follow up',
        'aproval' => 'approval',
        'traning' => 'training',
        'latihan' => 'training',
        'modle' => 'module',
        'modul' => 'module',
        'cert' => 'certificate',
        'certs' => 'certificate',
        'ecert' => 'certificate',
        'ecerts' => 'certificate',
        'sop' => 'sop',
        'sops' => 'sop',
        'rule' => 'rules',
        'regulation' => 'regulations',
        'regulations' => 'regulations',
        'policy' => 'policy',
        'policies' => 'policy',
        'procedure' => 'procedure',
        'procedures' => 'procedure',
        'quotation' => 'quotation',
        'quote' => 'quotation',
        'quotes' => 'quotation',
        'sebutharga' => 'quotation',
        'proposal' => 'proposal',
        'proposals' => 'proposal',
        'cadangan' => 'proposal',
        'costing' => 'costing',
        'costings' => 'costing',
        'estimate' => 'estimate',
        'estimates' => 'estimate',
        'estimation' => 'estimate',
        'pricing' => 'pricing',
        'price' => 'price',
        'scope' => 'scope',
        'scoping' => 'scope',
        'writeup' => 'write up',
        'write-up' => 'write up',
        'minutes' => 'meeting minutes',
        'mom' => 'meeting minutes',
        'memo' => 'memo',
        'brief' => 'briefing note',
        'briefing' => 'briefing',
        'compose' => 'write',
        'composing' => 'write',
        'writing' => 'write',
        'written' => 'write',
        'prep' => 'prepare',
        'editing' => 'edit',
        'edited' => 'edit',
        'amendment' => 'revise',
        'conducting' => 'conduct',
        'shooting' => 'shoot',
        'shot' => 'shoot',
        'pickup' => 'pick up',
        'frq' => 'rfq',
        'namelist' => 'list',
        'skrip' => 'script',
        'vacancies' => 'vacancy',
        'preparing' => 'prepare',
        'prepared' => 'prepare',
        'creating' => 'create',
        'created' => 'create',
        'drafting' => 'draft',
        'drafted' => 'draft',
        'reviewing' => 'review',
        'reviewed' => 'review',
        'revising' => 'revise',
        'revised' => 'revise',
        'produce' => 'create',
        'producing' => 'create',
        'generate' => 'create',
        'generating' => 'create',
        'formulate' => 'create',
        'formulating' => 'create',
        'outline' => 'draft',
        'outlining' => 'draft',
        'make' => 'make',
        'doing' => 'do',
        'rekod' => 'record',
        'borang' => 'form',
        'dokumen' => 'document',
        'kelulusan' => 'approval',
        'maklumbalas' => 'feedback',
        'pembayaran' => 'payment',
        'bayaran' => 'payment',
        'aduan' => 'complaint',
        'komplen' => 'complaint',
        'kemalangan' => 'accident',
        'pematuhan' => 'compliance',
        'sistem' => 'system',
        'analisis' => 'analysis',
        'architecting' => 'architect',
        'refactoring' => 'refactor',
        'refactored' => 'refactor',
        'optimise' => 'optimize',
        'optimising' => 'optimize',
        'optimizing' => 'optimize',
        'automating' => 'automate',
        'migration' => 'migration',
        'migrating' => 'migrate',
        'integrating' => 'integrate',
        'forecasting' => 'forecast',
        'budgeting' => 'budget',
        'planning' => 'plan',
        'strategi' => 'strategy',
        'bajet' => 'budget',
        'risiko' => 'risk',
        'ciri' => 'feature',
        'baru' => 'new',
        'migrasi' => 'migration',
        'arkitektur' => 'architecture',
        'debugging' => 'debug',
        'bugfix' => 'fix bug',
        'bugfixing' => 'fix bug',
        'bugfixes' => 'fix bug',
        'frontend' => 'frontend',
        'backend' => 'backend',
        'api' => 'api',
        'hr' => 'hr',
        'payroll' => 'payroll',
        'claims' => 'claim',
        'claiming' => 'claim',
        'account' => 'account',
        'accounts' => 'account',
        'reconciliation' => 'reconcile',
        'reconciling' => 'reconcile',
        'reconciled' => 'reconcile',
        'invoicing' => 'invoice',
        'cashflow' => 'cash flow',
        'cash-flow' => 'cash flow',
        'forecasted' => 'forecast',
        'website' => 'website',
        'webpage' => 'page',
        'outstations' => 'outstation',
        'overnights' => 'overnight',
        'training' => 'training',
        'trainings' => 'training',
        'trainer' => 'trainer',
        'kursus' => 'training',
        'latih' => 'training',
        'mengajar' => 'teach',
        'ajar' => 'teach',
        'sesi' => 'session',
        'lawatan' => 'visit',
        'tapak' => 'site',
        'luar' => 'outstation',
        'kawasan' => 'outstation',
        'bermalam' => 'overnight',
        'peraturan' => 'regulations',
        'polisi' => 'policy',
        'prosedur' => 'procedure',
        'tuntutan' => 'claim',
    ];

    public function classifyTitle(string $title): array
    {
        $normalizedTitle = $this->normalizeText($title);

        $trashPattern = $this->trashInputPattern($title, $normalizedTitle);
        if ($trashPattern !== null) {
            return $this->withWorkType($this->classificationForCategory('non_work', [
                'confidence' => 'high',
                'matched_pattern' => $trashPattern,
            ]), $normalizedTitle);
        }

        if ($normalizedTitle === '') {
            return $this->withWorkType($this->classificationForCategory('uncategorised'), $normalizedTitle);
        }

        if (strlen($normalizedTitle) < 3) {
            return $this->withWorkType($this->classificationForCategory('unclear_unrated', [
                'confidence' => 'low',
                'matched_pattern' => 'unclear:no_work_signal',
            ]), $normalizedTitle);
        }

        $nonWorkPattern = $this->matchingNonWorkPattern($title, $normalizedTitle);
        if ($nonWorkPattern !== null) {
            return $this->withWorkType($this->classificationForCategory('non_work', [
                'confidence' => 'high',
                'matched_pattern' => $nonWorkPattern,
            ]), $normalizedTitle);
        }

        $structuredRule = $this->structuredRule($normalizedTitle);
        if ($structuredRule !== null) {
            return $this->withWorkType($this->classificationForCategory($structuredRule['category'], [
                'confidence' => 'high',
                'matched_pattern' => 'rule:'.$structuredRule['intent'],
            ]), $normalizedTitle);
        }

        foreach (self::TASK_CLASSIFICATION_PATTERNS as $pattern) {
            if (
                str_contains($normalizedTitle, $pattern['pattern'])
                || $this->tokenSequenceMatches($normalizedTitle, $pattern['pattern'])
            ) {
                return $this->withWorkType($this->classificationForCategory($pattern['category'], [
                    'confidence' => 'high',
                    'matched_pattern' => $pattern['pattern'],
                ]), $normalizedTitle);
            }
        }

        $learnedClassification = $this->learnedClassification($normalizedTitle);
        if ($learnedClassification !== null) {
            return $learnedClassification;
        }

        if (
            count(explode(' ', $normalizedTitle)) < 2
            || ! $this->hasDistinctiveWorkloadTerm($normalizedTitle)
        ) {
            return $this->withWorkType($this->fallbackClassification($normalizedTitle), $normalizedTitle);
        }

        $fuzzyMatch = $this->bestFuzzyPatternMatch($normalizedTitle);
        if ($fuzzyMatch === null) {
            return $this->withWorkType($this->fallbackClassification($normalizedTitle), $normalizedTitle);
        }

        return $this->withWorkType($this->classificationForCategory($fuzzyMatch['category'], [
            'confidence' => $fuzzyMatch['score'] <= self::FUZZY_HIGH_CONFIDENCE_THRESHOLD ? 'high' : 'medium',
            'matched_pattern' => $fuzzyMatch['pattern'],
        ]), $normalizedTitle);
    }

    public function normalizedTitleForLearning(string $title): string
    {
        return $this->normalizeText($title);
    }

    private function learnedClassification(string $normalizedTitle): ?array
    {
        return app(TaskLearnedClassificationService::class)->lookup($normalizedTitle);
    }

    private function fallbackClassification(string $normalizedTitle): array
    {
        if ($this->hasCompanyWorkSignal($normalizedTitle)) {
            return $this->classificationForCategory('uncategorised');
        }

        return $this->classificationForCategory('unclear_unrated', [
            'confidence' => 'low',
            'matched_pattern' => 'unclear:no_work_signal',
        ]);
    }

    private function hasCompanyWorkSignal(string $text): bool
    {
        if ($this->hasCompanyWorkAction($text)) {
            return true;
        }

        $signalTerms = $this->companyWorkSignalTerms();
        foreach ($this->tokens($text) as $token) {
            if (isset($signalTerms[$token])) {
                return true;
            }
        }

        return false;
    }

    private function trashInputPattern(string $title, string $normalizedTitle): ?string
    {
        $rawTitle = trim($title);
        if ($rawTitle === '') {
            return null;
        }

        $visibleText = preg_replace('/\s+/', '', $rawTitle) ?? '';
        if ($normalizedTitle === '' && strlen($visibleText) >= 3) {
            return 'trash:symbols';
        }

        $tokens = $this->rawTokens($rawTitle);
        if (empty($tokens)) {
            return null;
        }

        if (count($tokens) >= 2 && count($tokens) <= 3 && $this->allTokensAreShortNoise($tokens)) {
            return 'trash:short_noise';
        }

        $trashTokens = array_filter($tokens, fn (string $token): bool => $this->isTrashToken($token));
        if (count($trashTokens) === count($tokens)) {
            return 'trash:gibberish';
        }

        return null;
    }

    private function allTokensAreShortNoise(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (isset(self::TYPO_PROTECTED_TOKENS[$token]) || strlen($token) >= 3) {
                return false;
            }
        }

        return true;
    }

    private function isTrashToken(string $token): bool
    {
        if (isset(self::TYPO_PROTECTED_TOKENS[$token])) {
            return false;
        }

        if (isset(self::TRASH_TOKENS[$token])) {
            return true;
        }

        if (strlen($token) >= 4 && ctype_digit($token)) {
            return true;
        }

        if (strlen($token) >= 4 && preg_match('/^(.)\1+$/', $token) === 1) {
            return true;
        }

        return false;
    }

    private function matchingNonWorkPattern(string $title, string $normalizedTitle): ?string
    {
        $rawNormalizedTitle = implode(' ', $this->tokensWithoutTypoCorrection($title));

        foreach (self::NON_WORK_EXACT_PATTERNS as $pattern) {
            if ($normalizedTitle === $pattern || $rawNormalizedTitle === $pattern) {
                return 'non_work:'.$pattern;
            }
        }

        foreach (self::NON_WORK_PATTERNS as $pattern) {
            $patternTokens = $this->tokensWithoutTypoCorrection($pattern);
            if (count($patternTokens) <= 1) {
                if ($normalizedTitle === $pattern || $rawNormalizedTitle === $pattern) {
                    return 'non_work:'.$pattern;
                }

                continue;
            }

            if (
                str_contains($normalizedTitle, $pattern)
                || str_contains($rawNormalizedTitle, $pattern)
                || $this->tokensIncludeSequence($this->tokens($normalizedTitle), $patternTokens)
                || $this->tokensIncludeSequence($this->tokensWithoutTypoCorrection($title), $patternTokens)
            ) {
                return 'non_work:'.$pattern;
            }
        }

        return null;
    }

    public function toResponse(array $classification): array
    {
        $category = (string) ($classification['task_category'] ?? 'uncategorised');
        $workType = self::normalizeWorkType((string) ($classification['work_type'] ?? $this->defaultWorkTypeForCategory($category)));

        return [
            'taskCategory' => $category,
            'taskCategoryLabel' => self::TASK_CATEGORY_DEFINITIONS[$category]['label']
                ?? self::TASK_CATEGORY_DEFINITIONS['uncategorised']['label'],
            'effortScore' => (float) ($classification['effort_score'] ?? 1),
            'classificationConfidence' => (string) ($classification['classification_confidence'] ?? 'low'),
            'classificationSource' => (string) ($classification['classification_source'] ?? 'system'),
            'userOverride' => (bool) ($classification['user_override'] ?? false),
            'matchedPattern' => $classification['matched_pattern'] ?? null,
            'workType' => $workType,
            'workTypeLabel' => self::workTypeLabel($workType),
            'workTypeConfidence' => (string) ($classification['work_type_confidence'] ?? 'low'),
            'workTypeMatchedPattern' => $classification['work_type_matched_pattern'] ?? null,
        ];
    }

    public function insertColumns(array $classification, bool $includeWorkType = true): array
    {
        $columns = [
            'task_category' => $classification['task_category'],
            'effort_score' => $classification['effort_score'],
            'classification_confidence' => $classification['classification_confidence'],
            'classification_source' => $classification['classification_source'],
            'user_override' => $classification['user_override'],
            'matched_pattern' => $classification['matched_pattern'],
        ];

        if ($includeWorkType) {
            $columns['work_type'] = self::normalizeWorkType((string) ($classification['work_type'] ?? 'unclear'));
            $columns['work_type_confidence'] = $classification['work_type_confidence'] ?? 'low';
            $columns['work_type_matched_pattern'] = $classification['work_type_matched_pattern'] ?? null;
        }

        return $columns;
    }

    private function withWorkType(array $classification, string $normalizedTitle): array
    {
        $category = (string) ($classification['task_category'] ?? 'uncategorised');
        $workType = $this->workTypeForClassification($category, $normalizedTitle, $classification);

        return array_merge($classification, $workType);
    }

    private function workTypeForClassification(string $category, string $normalizedTitle, array $classification): array
    {
        if ($category === 'non_work') {
            return $this->classificationForWorkType('non_work', 'high', $classification['matched_pattern'] ?? 'non_work');
        }

        if ($category === 'unclear_unrated' || $normalizedTitle === '') {
            return $this->classificationForWorkType('unclear', 'low', $classification['matched_pattern'] ?? 'unclear:no_work_signal');
        }

        if ($category === 'administrative') {
            return $this->classificationForWorkType('clerical_admin', 'medium', 'work_type:category:administrative');
        }

        $match = $this->matchingWorkTypeRule($normalizedTitle);
        if ($match !== null) {
            return $this->classificationForWorkType($match['type'], 'high', 'work_type:'.$match['type'].':'.$match['term']);
        }

        return $this->classificationForWorkType($this->defaultWorkTypeForCategory($category), 'medium', 'work_type:category:'.$category);
    }

    private function matchingWorkTypeRule(string $normalizedTitle): ?array
    {
        $tokens = $this->tokens($normalizedTitle);
        foreach (self::WORK_TYPE_RULES as $type => $terms) {
            foreach ($terms as $term) {
                if ($this->tokensIncludeTerm($tokens, (string) $term)) {
                    return ['type' => $type, 'term' => (string) $term];
                }
            }
        }

        return null;
    }

    private function classificationForWorkType(string $workType, string $confidence, ?string $matchedPattern): array
    {
        $type = self::normalizeWorkType($workType);

        return [
            'work_type' => $type,
            'work_type_confidence' => $confidence,
            'work_type_matched_pattern' => $matchedPattern,
        ];
    }

    private function defaultWorkTypeForCategory(string $category): string
    {
        return match ($category) {
            'non_work' => 'non_work',
            'unclear_unrated' => 'unclear',
            'administrative' => 'clerical_admin',
            'pending_waiting', 'coordination_follow_up' => 'coordination_followup',
            default => 'unclear',
        };
    }

    public static function normalizeWorkType(string $workType): string
    {
        return array_key_exists($workType, self::WORK_TYPE_DEFINITIONS) ? $workType : 'unclear';
    }

    public static function workTypeLabel(string $workType): string
    {
        return self::WORK_TYPE_DEFINITIONS[self::normalizeWorkType($workType)];
    }

    public static function taskCategoryDefinitions(): array
    {
        return self::TASK_CATEGORY_DEFINITIONS;
    }

    public static function workTypeDefinitions(): array
    {
        return self::WORK_TYPE_DEFINITIONS;
    }

    public static function normalizeTaskCategory(string $category): string
    {
        return array_key_exists($category, self::TASK_CATEGORY_DEFINITIONS) ? $category : 'uncategorised';
    }

    public static function effortScoreForCategory(string $category): float
    {
        $normalizedCategory = self::normalizeTaskCategory($category);

        return (float) self::TASK_CATEGORY_DEFINITIONS[$normalizedCategory]['effort_score'];
    }

    private function classificationForCategory(string $category, array $options = []): array
    {
        $taskCategory = array_key_exists($category, self::TASK_CATEGORY_DEFINITIONS)
            ? $category
            : 'uncategorised';

        return [
            'task_category' => $taskCategory,
            'effort_score' => self::TASK_CATEGORY_DEFINITIONS[$taskCategory]['effort_score'],
            'classification_confidence' => $options['confidence'] ?? 'low',
            'classification_source' => 'system',
            'user_override' => false,
            'matched_pattern' => $options['matched_pattern'] ?? null,
        ];
    }

    private function bestFuzzyPatternMatch(string $normalizedTitle): ?array
    {
        $bestMatch = null;

        foreach (self::TASK_CLASSIFICATION_PATTERNS as $pattern) {
            $normalizedPattern = implode(' ', $this->tokensWithoutTypoCorrection($pattern['pattern']));
            $maxLength = max(strlen($normalizedTitle), strlen($normalizedPattern));
            if ($maxLength === 0) {
                continue;
            }

            $score = levenshtein($normalizedTitle, $normalizedPattern) / $maxLength;
            if ($score > self::FUZZY_MATCH_THRESHOLD) {
                continue;
            }

            if ($bestMatch === null || $score < $bestMatch['score']) {
                $bestMatch = [
                    'pattern' => $pattern['pattern'],
                    'category' => $pattern['category'],
                    'score' => $score,
                ];
            }
        }

        return $bestMatch;
    }

    private function hasDistinctiveWorkloadTerm(string $text): bool
    {
        $distinctiveTerms = $this->distinctiveWorkloadTerms();

        foreach ($this->tokens($text) as $token) {
            if (isset($distinctiveTerms[$token])) {
                return true;
            }
        }

        return false;
    }

    private function hasCompanyWorkAction(string $text): bool
    {
        $actionTerms = $this->companyWorkActionTerms();
        $tokens = $this->tokens($text);

        foreach ($actionTerms as $term) {
            if ($this->tokensIncludeSequence($tokens, $term)) {
                return true;
            }
        }

        return false;
    }

    private function companyWorkActionTerms(): array
    {
        static $terms = null;

        if ($terms !== null) {
            return $terms;
        }

        $terms = [];
        foreach (self::TASK_CLASSIFICATION_RULES as $rule) {
            foreach (($rule['verbs'] ?? []) as $verb) {
                $tokens = $this->tokensWithoutTypoCorrection((string) $verb);
                if (
                    ! empty($tokens)
                    && ! isset(self::FALLBACK_ACTION_EXCLUDED_TERMS[implode(' ', $tokens)])
                ) {
                    $terms[implode(' ', $tokens)] = $tokens;
                }
            }
        }

        return array_values($terms);
    }

    private function companyWorkSignalTerms(): array
    {
        static $terms = null;

        if ($terms !== null) {
            return $terms;
        }

        $terms = [];
        foreach (self::TASK_CLASSIFICATION_PATTERNS as $pattern) {
            $this->addWorkSignalTokens($terms, (string) ($pattern['pattern'] ?? ''));
        }

        foreach (self::TASK_CLASSIFICATION_RULES as $rule) {
            foreach (['requiredAny', 'objects'] as $field) {
                foreach (($rule[$field] ?? []) as $term) {
                    $this->addWorkSignalTokens($terms, (string) $term);
                }
            }
        }

        return $terms;
    }

    private function addWorkSignalTokens(array &$terms, string $text): void
    {
        foreach ($this->tokensWithoutTypoCorrection($text) as $token) {
            if (
                strlen($token) < 3
                || isset(self::TYPO_PROTECTED_TOKENS[$token])
                || isset(self::WORK_SIGNAL_STOP_TOKENS[$token])
            ) {
                continue;
            }

            $terms[$token] = true;
        }
    }

    private function distinctiveWorkloadTerms(): array
    {
        static $terms = null;

        if ($terms !== null) {
            return $terms;
        }

        $terms = [];
        foreach (self::TASK_CLASSIFICATION_PATTERNS as $pattern) {
            foreach ($this->tokensWithoutTypoCorrection($pattern['pattern']) as $token) {
                $terms[$token] = true;
            }
        }

        return $terms;
    }

    private function normalizeText(string $text): string
    {
        return implode(' ', $this->tokens($text));
    }

    private function tokens(string $text): array
    {
        $canonicalTokens = [];

        foreach ($this->rawTokens($text) as $token) {
            $normalizedToken = array_key_exists($token, self::TASK_CLASSIFICATION_TOKEN_ALIASES)
                ? $token
                : $this->correctTokenTypo($token);
            $alias = self::TASK_CLASSIFICATION_TOKEN_ALIASES[$normalizedToken] ?? $normalizedToken;
            foreach (array_filter(explode(' ', $alias)) as $aliasToken) {
                $canonicalTokens[] = $aliasToken;
            }
        }

        return $canonicalTokens;
    }

    private function rawTokens(string $text): array
    {
        $normalized = strtolower(trim($text));
        $normalized = preg_replace('/[^\w\s]/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return array_values(array_filter(explode(' ', trim($normalized))));
    }

    private function tokensWithoutTypoCorrection(string $text): array
    {
        $canonicalTokens = [];

        foreach ($this->rawTokens($text) as $token) {
            $alias = self::TASK_CLASSIFICATION_TOKEN_ALIASES[$token] ?? $token;
            foreach (array_filter(explode(' ', $alias)) as $aliasToken) {
                $canonicalTokens[] = $aliasToken;
            }
        }

        return $canonicalTokens;
    }

    private function correctTokenTypo(string $token): string
    {
        static $corrections = [];

        if (array_key_exists($token, $corrections)) {
            return $corrections[$token];
        }

        if (
            strlen($token) < self::TYPO_CORRECTION_MIN_LENGTH
            || isset(self::TYPO_PROTECTED_TOKENS[$token])
        ) {
            return $corrections[$token] = $token;
        }

        $vocabulary = $this->workloadVocabulary();
        if (isset($vocabulary[$token])) {
            return $corrections[$token] = $token;
        }

        $tokenLength = strlen($token);
        $maxDistance = $tokenLength <= 5 ? 1 : 2;
        $bestToken = null;
        $bestDistance = $maxDistance + 1;
        $hasTie = false;
        $vocabularyByLength = $this->workloadVocabularyByLength();

        for ($length = $tokenLength - $maxDistance; $length <= $tokenLength + $maxDistance; $length++) {
            foreach (($vocabularyByLength[$length] ?? []) as $candidate) {
                if (
                    $candidate === $token
                    || strlen($candidate) < self::TYPO_CORRECTION_MIN_LENGTH
                    || isset(self::TYPO_PROTECTED_TOKENS[$candidate])
                ) {
                    continue;
                }

                $distance = $this->typoDistance($token, $candidate);
                if ($distance > $maxDistance || $distance > $bestDistance) {
                    continue;
                }

                if ($distance === $bestDistance) {
                    $hasTie = true;

                    continue;
                }

                $bestToken = $candidate;
                $bestDistance = $distance;
                $hasTie = false;
            }
        }

        return $corrections[$token] = $bestToken !== null && ! $hasTie ? $bestToken : $token;
    }

    private function workloadVocabulary(): array
    {
        static $vocabulary = null;

        if ($vocabulary !== null) {
            return $vocabulary;
        }

        $vocabulary = [];
        foreach (self::TASK_CLASSIFICATION_TOKEN_ALIASES as $token => $alias) {
            $vocabulary[$token] = true;
            foreach ($this->tokensWithoutTypoCorrection($alias) as $aliasToken) {
                $vocabulary[$aliasToken] = true;
            }
        }

        foreach (self::TASK_CLASSIFICATION_PATTERNS as $pattern) {
            foreach ($this->tokensWithoutTypoCorrection((string) ($pattern['pattern'] ?? '')) as $token) {
                $vocabulary[$token] = true;
            }
        }

        foreach (self::TASK_CLASSIFICATION_RULES as $rule) {
            foreach (['requiredAny', 'verbs', 'objects', 'negativeObjects'] as $field) {
                foreach (($rule[$field] ?? []) as $term) {
                    foreach ($this->tokensWithoutTypoCorrection((string) $term) as $token) {
                        $vocabulary[$token] = true;
                    }
                }
            }
        }

        foreach (self::WORK_TYPE_RULES as $terms) {
            foreach ($terms as $term) {
                foreach ($this->tokensWithoutTypoCorrection((string) $term) as $token) {
                    $vocabulary[$token] = true;
                }
            }
        }

        return $vocabulary;
    }

    private function workloadVocabularyByLength(): array
    {
        static $vocabularyByLength = null;

        if ($vocabularyByLength !== null) {
            return $vocabularyByLength;
        }

        $vocabularyByLength = [];
        foreach (array_keys($this->workloadVocabulary()) as $token) {
            $vocabularyByLength[strlen($token)][] = $token;
        }

        return $vocabularyByLength;
    }

    private function typoDistance(string $source, string $target): int
    {
        $levenshteinDistance = levenshtein($source, $target);
        if ($levenshteinDistance <= 1) {
            return $levenshteinDistance;
        }

        return min($levenshteinDistance, $this->damerauLevenshteinDistance($source, $target));
    }

    private function damerauLevenshteinDistance(string $source, string $target): int
    {
        $sourceLength = strlen($source);
        $targetLength = strlen($target);
        $distances = [];

        for ($i = 0; $i <= $sourceLength; $i++) {
            $distances[$i] = [$i];
        }
        for ($j = 0; $j <= $targetLength; $j++) {
            $distances[0][$j] = $j;
        }

        for ($i = 1; $i <= $sourceLength; $i++) {
            for ($j = 1; $j <= $targetLength; $j++) {
                $cost = $source[$i - 1] === $target[$j - 1] ? 0 : 1;
                $distances[$i][$j] = min(
                    $distances[$i - 1][$j] + 1,
                    $distances[$i][$j - 1] + 1,
                    $distances[$i - 1][$j - 1] + $cost
                );

                if (
                    $i > 1
                    && $j > 1
                    && $source[$i - 1] === $target[$j - 2]
                    && $source[$i - 2] === $target[$j - 1]
                ) {
                    $distances[$i][$j] = min($distances[$i][$j], $distances[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $distances[$sourceLength][$targetLength];
    }

    private function tokenSequenceMatches(string $text, string $pattern): bool
    {
        return $this->tokensIncludeTerm($this->tokens($text), $pattern);
    }

    private function structuredRule(string $text): ?array
    {
        $tokens = $this->tokens($text);

        foreach (self::TASK_CLASSIFICATION_RULES as $rule) {
            if ($this->structuredRuleMatches($rule, $tokens)) {
                return $rule;
            }
        }

        return null;
    }

    private function structuredRuleMatches(array $rule, array $tokens): bool
    {
        foreach (($rule['negativeObjects'] ?? []) as $term) {
            if ($this->tokensIncludeTerm($tokens, (string) $term)) {
                return false;
            }
        }

        if (array_key_exists('requiredAny', $rule) && is_array($rule['requiredAny'])) {
            foreach ($rule['requiredAny'] as $term) {
                if ($this->tokensIncludeTerm($tokens, (string) $term)) {
                    return true;
                }
            }

            return false;
        }

        $verbs = $rule['verbs'] ?? null;
        $objects = $rule['objects'] ?? null;
        $hasVerb = ! is_array($verbs) || $this->anyTermMatches($tokens, $verbs);
        $hasObject = ! is_array($objects) || $this->anyTermMatches($tokens, $objects);

        return $hasVerb && $hasObject;
    }

    private function anyTermMatches(array $tokens, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->tokensIncludeTerm($tokens, (string) $term)) {
                return true;
            }
        }

        return false;
    }

    private function tokensIncludeTerm(array $tokens, string $term): bool
    {
        $termTokens = $this->tokensWithoutTypoCorrection($term);

        return $this->tokensIncludeSequence($tokens, $termTokens);
    }

    private function tokensIncludeSequence(array $tokens, array $termTokens): bool
    {
        if (empty($tokens) || empty($termTokens)) {
            return false;
        }

        $cursor = 0;
        foreach ($tokens as $token) {
            if ($token === $termTokens[$cursor]) {
                $cursor++;
                if ($cursor === count($termTokens)) {
                    return true;
                }
            }
        }

        return false;
    }
}
