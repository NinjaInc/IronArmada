<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Outgame / Landing page - English
    |--------------------------------------------------------------------------
    */

    // Browser outdated warning
    'browser_warning' => [
        'title'  => 'Your browser is not up to date.',
        'desc1'  => 'Your Internet Explorer version does not correspond to the existing standards and is not supported by this website anymore.',
        'desc2'  => 'To use this website please update your web browser to a current version or use another web browser. If you are already using the latest version, please reload the page to display it properly.',
        'desc3'  => "Here's a list of the most popular browsers. Click on one of the symbols to get to the download page:",
    ],

    // Login form (header)
    'login' => [
        'page_title'        => 'Iron Armada - Conquer the universe',
        'btn'               => 'Login',
        'email_label'       => 'Email address:',
        'password_label'    => 'Password:',
        'universe_label'    => 'Universe:',
        'universe_option_1' => '1. Universe',
        'submit'            => 'Log in',
        'forgot_password'   => 'Forgot your password?',
        'forgot_email'      => 'Forgot your email address?',
        'terms_accept_html' => 'With the login I accept the <a class="overlay" data-type="ajax" href="/ajax/main/terms" title="T&amp;Cs">T&amp;Cs</a>',
    ],

    // Registration form (sidebar)
    'register' => [
        'play_free'    => 'PLAY FOR FREE!',
        'email_label'  => 'Email address:',
        'password_label' => 'Password:',
        'universe_label' => 'Universe:',
        'distinctions' => 'Distinctions',
        'terms_html'   => 'Our <a class="overlay" data-type="ajax" href="/ajax/main/terms" title="T&amp;Cs">T&amp;Cs</a> and <a class="overlay" data-type="ajax" href="/ajax/main/privacy-policy" title="Privacy Policy">Privacy Policy</a> apply in the game',
        'submit'       => 'Register',
    ],

    // Top navigation tabs
    'nav' => [
        'home'  => 'Home',
        'about' => 'About Iron Armada',
        'media' => 'Media',
        'wiki'  => 'Wiki',
    ],

    // Home tab content
    'home' => [
        'title'            => 'Iron Armada - Conquer the universe',
        'description_html' => '<em>Iron Armada</em> is a private space strategy game for a small crew of commanders. Build mines, research tech, raise a fleet and take the galaxy. Fast universe, no pay-to-win, no dark matter shop. You only need a web browser to play.',
        'board_btn'        => 'Board',
        'trailer_title'    => 'Trailer',
        'skip_link'        => 'Skip to log in',
        'footer_nav'       => 'Legal',
        'language_nav'     => 'Language',
        'hero_title'       => 'Build the yard. Launch the fleet.',
        'hero_text'        => 'Iron Armada is a private space strategy game for a small crew. Mine resources, research better hulls and take the galaxy one system at a time.',
        'tabs_label'       => 'Account',
        'tab_login'        => 'Log in',
        'tab_register'     => 'Create account',
        'field_email'      => 'Email',
        'field_password'   => 'Password',
        'field_universe'   => 'Universe',
        'password_hint'    => 'At least 8 characters.',
        'login_submit'     => 'Log in',
        'register_submit'  => 'Create account',
        'register_terms_html' => 'By creating an account you accept the <a class="overlay" data-type="ajax" href="/ajax/main/terms">T&amp;Cs</a> and <a class="overlay" data-type="ajax" href="/ajax/main/privacy-policy">Privacy Policy</a>.',
        'loop_label'       => 'How the game works',
        'loop' => [
            'mine' => [
                'title' => 'Mine',
                'text'  => 'Metal, crystal and deuterium pay for everything. Build your mines first and keep the power on.',
            ],
            'research' => [
                'title' => 'Research',
                'text'  => 'Sharper weapons, thicker armour and faster drives. Every upgrade changes what your fleet can do.',
            ],
            'fight' => [
                'title' => 'Fight',
                'text'  => 'Raid your neighbours, defend your colonies and recycle the debris. Fleets fly in real time.',
            ],
        ],
    ],

    // Archon Tech links (edit URLs here)
    'links' => [
        'archon_tech'        => 'Archon Tech',
        'archon_tech_url'    => 'https://archontech.au',
        'archon_hosting'     => 'Archon Hosting',
        'archon_hosting_url' => 'https://archonhosting.au',
        'support'            => 'Support',
        'support_url'        => 'https://archontech.au',
    ],

    // Footer
    'footer' => [
        'legal'          => 'Legal',
        'privacy_policy' => 'Privacy Policy',
        'terms'          => 'T&Cs',
        'contact'        => 'Contact',
        'rules'          => 'Rules',
        'copyright'      => 'Iron Armada · An Archon Tech project. Private fan project, not affiliated with GameForge or OGame. Based on OGameX (MIT licence).',
    ],

    // Inline JS strings
    'js' => [
        'login'            => 'Login',
        'close'            => 'Close',
        'age_check_failed' => 'We are sorry, but you are not eligible to register. Please see our T&C for more information.',
    ],

    // jQuery ValidationEngine strings
    'validation' => [
        'required'                  => 'This field is required',
        'make_decision'             => 'Make a decision',
        'accept_terms'              => 'You must accept the T&Cs.',
        'length'                    => 'Between 3 and 20 characters allowed.',
        'pw_length'                 => 'Between 4 and 20 characters allowed.',
        'email'                     => 'You need to enter a valid email address!',
        'invalid_chars'             => 'Contains invalid characters.',
        'no_begin_end_underscore'   => 'Your name may not start or end with an underscore.',
        'no_begin_end_whitespace'   => 'Your name may not start or end with a space.',
        'max_three_underscores'     => 'Your name may not contain more than 3 underscores in total.',
        'max_three_whitespaces'     => 'Your name may not include more than 3 spaces in total.',
        'no_consecutive_underscores' => 'You may not use two or more underscores one after the other.',
        'no_consecutive_whitespaces' => 'You may not use two or more spaces one after the other.',
        'username_available'        => 'This username is available.',
        'username_loading'          => 'Please wait, loading...',
        'username_taken'            => 'This username is not available anymore.',
        'only_letters'              => 'Use characters only.',
    ],

    // Universe selection characteristics tooltip texts
    'universe_characteristics' => [
        'fleet_speed'     => 'Fleet Speed: the higher the value, the less time you have left to react to an attack.',
        'economy_speed'   => 'Economy Speed: the higher the value, the faster constructions and research will be completed and resources gathered.',
        'debris_ships'    => 'Some of the ships destroyed in battle will enter the debris field.',
        'debris_defence'  => 'Some of the defensive structures destroyed in battle will enter the debris field.',
        'dark_matter_gift' => 'You will receive Dark Matter as a reward for confirming your email address.',
        'aks_on'          => 'Alliance battle system activated',
        'planet_fields'   => 'The maximum amount of building slots has been increased.',
        'wreckfield'      => 'Space Dock activated: some destroyed ships can be restored using the Space Dock.',
        'universe_big'    => 'Amount of Galaxies in the Universe',
    ],
];
