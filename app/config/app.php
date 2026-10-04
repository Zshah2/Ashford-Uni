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
        ['label' => 'Calendar', 'href' => '/calendar'],
        ['label' => 'Fall 2026', 'href' => '/schedule/fall-2026'],
        ['label' => 'Spring 2027', 'href' => '/schedule/spring-2027'],
        ['label' => 'Catalog', 'href' => '/catalog'],
    ],
    'cta' => [
        'primary' => ['label' => 'Apply Now', 'href' => '#admissions'],
        'secondary' => ['label' => 'Admin sign in', 'href' => '/login.php'],
    ],
];

