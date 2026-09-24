<?php

return [
    'site' => [
        'name' => 'Ashford College',
        'shortName' => 'Ashford',
        'tagline' => 'A modern campus for ambitious learners.',
        'description' => 'Ashford College offers career-focused programs, supportive faculty, and a vibrant campus community.',
        'themeColor' => '#0a0f1f',
    ],
    'registration' => [
        // Fallback when a student has no ug_credit_limits row yet.
        'default_max_credits' => 18,
    ],
    'nav' => [
        ['label' => 'Academics', 'href' => '#programs'],
        ['label' => 'Research', 'href' => '#departments'],
        ['label' => 'Campus Life', 'href' => '#visit'],
        ['label' => 'Admissions', 'href' => '#admissions'],
        ['label' => 'About', 'href' => '#about'],
        ['label' => 'News', 'href' => '#news'],
        ['label' => 'Events', 'href' => '#events'],
    ],
    'cta' => [
        'primary' => ['label' => 'Apply Now', 'href' => '#admissions'],
        'secondary' => ['label' => 'Admin sign in', 'href' => '/login.php'],
    ],
];

