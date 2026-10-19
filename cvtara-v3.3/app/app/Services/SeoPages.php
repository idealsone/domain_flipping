<?php

namespace App\Services;

/**
 * Programmatic SEO pages, built from resources/data/seo-pages.json (source: tools/seo/*.py).
 *
 * Every page is server-rendered with breadcrumbs, an "On this page" list, a filled sample, FAQ, and links that tie the
 * page into its hub: India → state → city → city × role, role → category, interview and cover-letter pages, and every
 * page → ATS checker and pricing. Paths always end with a slash so the ready-made page cache can store them.
 */
class SeoPages
{
    private static ?array $data = null;

    /** Path prefixes owned by this class (used by the controller and the page cache). */
    public const PREFIXES = ['resume-examples', 'resume-templates', 'cover-letter-examples', 'interview-questions', 'resume-builder', 'resume', 'resume-words', 'ats-checker'];

    /** Existing guides and calculators that the programmatic pages link to. */
    private const GUIDES = [
        'summary' => ['/guides/resume/professional-summary-guide/', 'How to write a professional summary'],
        'bullets' => ['/guides/resume/achievements-bullet-points/', 'Turn duties into achievement bullet points'],
        'skills' => ['/guides/resume/skills-section-keywords/', 'Choosing skills and keywords for your resume'],
        'ats' => ['/guides/resume/ats-friendly-format-india/', 'ATS-friendly resume format for India'],
        'fresher' => ['/guides/resume/no-experience-format/', 'Resume format with no experience'],
        'projects' => ['/guides/resume/fresher-projects-guide/', 'How freshers should present projects'],
        'verbs' => ['/guides/resume/action-verbs-list/', 'Action verbs list for resumes'],
        'pdfword' => ['/guides/resume/pdf-vs-word-ats/', 'PDF or Word: which file to send'],
        'gap' => ['/guides/resume/gap-in-employment-explanation/', 'How to explain a gap in employment'],
        'tmay' => ['/guides/interview/tell-me-about-yourself/', 'How to answer "Tell me about yourself"'],
        'star' => ['/guides/interview/star-method-examples/', 'STAR method examples'],
        'ask' => ['/guides/interview/questions-to-ask-them/', 'Questions to ask the interviewer'],
        'thanks' => ['/guides/interview/thank-you-email-template/', 'Thank-you email after an interview'],
        'salary' => ['/guides/interview/salary-expectations-answer/', 'How to answer salary expectations'],
        'coverEmail' => ['/guides/cover-letter/short-email-format/', 'Short cover letter email format'],
        'followup' => ['/guides/jobs/follow-up-email-template/', 'Follow-up email after applying'],
        'tracker' => ['/guides/jobs/application-tracker-method/', 'A simple job application tracker'],
        'scams' => ['/guides/jobs/remote-job-scams-signs/', 'Signs of a job scam'],
        'ctc' => ['/calculators/ctc-to-in-hand-salary-calculator/', 'CTC to in-hand salary calculator'],
        'col' => ['/calculators/cost-of-living-calculator-by-city/', 'Cost of living calculator by city'],
    ];

    private const ATS_DEF = 'ATS (applicant tracking system, the software many employers use to store, search and filter resumes)';

    public static function data(): array
    {
        return self::$data ??= json_decode((string) file_get_contents(resource_path('data/seo-pages.json')), true);
    }

    public static function owns(string $path): bool
    {
        return (bool) preg_match('#^('.implode('|', array_map('preg_quote', self::PREFIXES)).')(/|$)#', $path);
    }

    /** Every page this class can render, as cache/sitemap paths with a trailing slash. */
    public static function paths(): array
    {
        $d = self::data();
        $p = ['resume-examples/', 'cover-letter-examples/', 'interview-questions/', 'resume-builder/india/', 'resume-words/', 'ats-checker/'];
        foreach ($d['categories'] as $c => $_) {
            $p[] = "resume-examples/$c/";
            if (self::rolesIn($c)) {
                $p[] = "cover-letter-examples/$c/";
            }
        }
        foreach ($d['roles'] as $r => $role) {
            $p[] = "resume-examples/{$role['c']}/$r/";
            $p[] = "interview-questions/$r/";
        }
        foreach ($d['templates'] as $t => $_) {
            $p[] = "resume-templates/$t/";
        }
        foreach ($d['states'] as $s => $_) {
            $p[] = "resume-builder/$s/";
            $p[] = "resume/$s/government-jobs/";
        }
        foreach ($d['cities'] as $c => $_) {
            $p[] = "resume-builder/$c/";
            foreach (array_keys(self::cityRoles($c)) as $r) {
                $p[] = "resume/$c/$r/";
            }
        }
        foreach ($d['words'] as $w => $_) {
            $p[] = "resume-words/$w/";
        }

        return array_values(array_unique($p));
    }

    /** Page model for a request path (without leading slash; trailing slash optional), or null. */
    public static function find(string $path): ?array
    {
        $d = self::data();
        $s = explode('/', trim($path, '/'));
        $n = count($s);
        $page = match (true) {
            $s[0] === 'resume-examples' && $n === 1 => self::examplesHub(),
            $s[0] === 'resume-examples' && $n === 2 && isset($d['categories'][$s[1]]) => self::category($s[1]),
            $s[0] === 'resume-examples' && $n === 3 && isset($d['roles'][$s[2]]) && $d['roles'][$s[2]]['c'] === $s[1] => self::role($s[2]),
            $s[0] === 'resume-templates' && $n === 2 && isset($d['templates'][$s[1]]) => self::template($s[1]),
            $s[0] === 'cover-letter-examples' && $n === 1 => self::coverHub(),
            $s[0] === 'cover-letter-examples' && $n === 2 && isset($d['categories'][$s[1]]) && self::rolesIn($s[1]) => self::cover($s[1]),
            $s[0] === 'interview-questions' && $n === 1 => self::interviewHub(),
            $s[0] === 'interview-questions' && $n === 2 && isset($d['roles'][$s[1]]) => self::interview($s[1]),
            $s[0] === 'resume-builder' && $n === 2 && $s[1] === 'india' => self::india(),
            $s[0] === 'resume-builder' && $n === 2 && isset($d['states'][$s[1]]) => self::state($s[1]),
            $s[0] === 'resume-builder' && $n === 2 && isset($d['cities'][$s[1]]) => self::city($s[1]),
            $s[0] === 'resume' && $n === 3 && isset($d['states'][$s[1]]) && $s[2] === 'government-jobs' => self::stateGov($s[1]),
            $s[0] === 'resume' && $n === 3 && isset($d['cities'][$s[1]]) && isset(self::cityRoles($s[1])[$s[2]]) => self::cityRole($s[1], $s[2]),
            $s[0] === 'resume-words' && $n === 1 => self::wordsHub(),
            $s[0] === 'resume-words' && $n === 2 && isset($d['words'][$s[1]]) => self::word($s[1]),
            $s[0] === 'ats-checker' && $n === 1 => self::atsChecker(),
            default => null,
        };
        if ($page) {
            $page['path'] = '/'.implode('/', $s).'/';
            $page['title'] = self::fit($page['title'], 50);
            $page['description'] = self::fit($page['description'], 155);
            $page['related'][] = ['Tools', [['/ats-checker/', 'Free ATS resume check'], ['/#pricing', 'Plans and pricing'], ['/templates.html', 'All resume templates'], ['/register', 'Build my resume free']]];
        }

        return $page;
    }

    // ---------------------------------------------------------------- data helpers

    private static function rolesIn(string $cat): array
    {
        return array_filter(self::data()['roles'], fn ($r) => $r['c'] === $cat);
    }

    /** City pages: Tier 1 uses the curated list; Tier 2 uses the shared roles plus its local specialty. Every city gets freshers. */
    private static function cityRoles(string $city): array
    {
        $d = self::data();
        $c = $d['cities'][$city];
        if ($c['tier'] === 1) {
            $out = $d['cityRoles'][$city] ?? [];
        } else {
            $out = [];
            foreach ($d['tier2Roles'] as $r) {
                $out[$r] = ['role' => $r, 'note' => ''];
            }
            $out[$c['sp'][0]] = ['role' => $c['sp'][0], 'note' => $c['sp'][1]];
        }
        $out['freshers'] ??= ['role' => 'freshers', 'note' => ''];

        return $out;
    }

    private static function stateName(string $st): string
    {
        return self::data()['states'][$st]['n'] ?? $st;
    }

    private static function stateOf(string $city): ?string
    {
        $st = self::data()['cities'][$city]['st'];

        return isset(self::data()['states'][$st]) ? $st : null;
    }

    private static function rolePath(string $r): string
    {
        return '/resume-examples/'.self::data()['roles'][$r]['c'].'/'.$r.'/';
    }

    /** Lower-case role words for running text, keeping acronyms such as HR, BPO, IT, QA. */
    private static function lc(string $t): string
    {
        return preg_replace_callback('/\b([A-Z][a-z]+)\b/', fn ($m) => strtolower($m[1]), $t);
    }

    private static function fit(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max - 1);

        return rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,;:-–').'…';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function ul(array $items, string $class = ''): string
    {
        return '<ul'.($class ? ' class="'.$class.'"' : '').'>'.implode('', array_map(fn ($i) => '<li>'.self::e($i).'</li>', $items)).'</ul>';
    }

    private static function links(array $pairs): string
    {
        return '<ul class="sp-links">'.implode('', array_map(fn ($l) => '<li><a href="'.self::e($l[0]).'">'.self::e($l[1]).'</a></li>', $pairs)).'</ul>';
    }

    private static function cards(array $items): string
    {
        // [path, title, text]
        return '<div class="sp-cards">'.implode('', array_map(fn ($c) => '<a class="sp-card" href="'.self::e($c[0]).'"><b>'.self::e($c[1]).'</b>'.($c[2] ?? '' ? '<span>'.self::e($c[2]).'</span>' : '').'</a>', $items)).'</div>';
    }

    private static function guides(string ...$keys): array
    {
        return array_map(fn ($k) => self::GUIDES[$k], $keys);
    }

    private static function crumbs(array ...$c): array
    {
        return array_merge([['Home', '/']], $c);
    }

    /** A filled sample resume rendered as a document, so readers (and search engines) see real text, not a picture. */
    private static function sample(array $r, string $city = 'Bangalore'): string
    {
        $name = 'Sample Candidate';
        $skills = implode(' · ', array_slice($r['k'], 0, 8));

        return '<figure class="sp-sample" aria-label="Sample '.self::e($r['t']).' resume">'
            .'<div class="sp-doc"><div class="sp-doc-head"><p class="sp-doc-name">'.$name.'</p><p class="sp-doc-role">'.self::e($r['t']).' · '.self::e($city).', India</p><p class="sp-doc-contact">email@example.com · +91 90000 00000 · linkedin.com/in/your-name</p></div>'
            .'<p class="sp-doc-h">Professional summary</p><p>'.self::e($r['s']).'</p>'
            .'<p class="sp-doc-h">Experience</p><p class="sp-doc-job"><b>'.self::e($r['t']).'</b> · Company name · 2022 – Present</p>'.self::ul($r['b'])
            .'<p class="sp-doc-h">Skills</p><p>'.self::e($skills).'</p>'
            .'<p class="sp-doc-h">Education</p><p>Degree, College name · Year</p></div>'
            .'<figcaption>Sample only. Replace every line and number with your own real work.</figcaption></figure>';
    }

    private static function image(string $kind, string $alt): array
    {
        return ['/assets/seo/'.$kind.'.svg', $alt];
    }

    private static function roleFaq(array $r): array
    {
        $t = self::lc($r['t']);

        return [
            ["What should a $t resume include?", 'A short summary, your experience with measured results, a skills section with the keywords from the job post, education and any certifications. Recruiters look for: '.implode(', ', array_slice($r['k'], 0, 4)).'.'],
            ["What skills should I put on a $t resume?", 'Use the skills the job post asks for that you really have. Common ones are '.implode(', ', array_slice($r['k'], 0, 6)).'.'],
            ["How do I make my $t resume ATS-friendly?", 'Use a single-column layout, standard headings such as Experience and Skills, and the exact skill names from the job description. Avoid text inside images, tables and icons for key details.'],
            ["How long should a $t resume be?", 'One page is enough for freshers and up to about 5 years of experience. Two pages suit longer careers. Cut older or unrelated lines before you shrink the font.'],
            ["Can freshers apply for $t roles?", $r['f']],
        ];
    }

    // ---------------------------------------------------------------- page builders

    private static function examplesHub(): array
    {
        $d = self::data();
        $cards = [];
        foreach ($d['categories'] as $c => $cat) {
            $n = count(self::rolesIn($c));
            $cards[] = ["/resume-examples/$c/", $cat['n'], $n ? "$n role examples" : 'Guide for this field'];
        }

        return ['kind' => 'resume-examples', 'eyebrow' => 'RESUME EXAMPLES', 'title' => 'Resume Examples by Job & Industry (India)', 'description' => 'Resume examples for Indian job seekers, grouped by industry and role, with sample summaries, achievement bullet points, skills and interview questions.',
            'h1' => 'Resume examples for every field', 'lead' => 'Pick your field, then your role. Each example shows a filled sample, the skills recruiters look for and what to change for your own resume.',
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/']), 'image' => self::image('resume-examples', 'Resume examples grouped by industry, shown as sample resume pages'),
            'sections' => [['fields', 'Browse by field', self::cards($cards)],
                ['how', 'How to use an example', self::ul(['Read what recruiters in your field check first.', 'Copy the structure, not the words. Write your own lines with your own results.', 'Match your skills section to the job post you are applying for.', 'Check it with a free scan by an '.self::ATS_DEF.' before you apply.'])]],
            'faq' => [['Can I copy a resume example?', 'Use it for structure and ideas only. Recruiters spot copied lines, and your resume must describe your real work.'], ['Which resume format is best in India?', 'Reverse-chronological (latest job first) suits most people. Freshers should put education and projects first.'], ['Do resume examples work with ATS?', 'Yes, when you keep a simple layout and use the job’s keywords. The examples here use standard headings.'], ['Are these examples free?', 'Yes. Reading examples and building your resume is free. A clean download needs a paid option.']],
            'related' => [['Guides', self::guides('ats', 'bullets', 'summary', 'fresher')]]];
    }

    private static function category(string $c): array
    {
        $cat = self::data()['categories'][$c];
        $roles = self::rolesIn($c);
        $sections = [['recruiters', 'What '.$cat['n'].' recruiters look for', '<p>'.self::e($cat['i']).'</p>'.self::ul($cat['h'], 'sp-check')],
            ['employers', 'Who hires in this field', self::ul($cat['e'])]];
        if ($roles) {
            $sections[] = ['roles', 'Resume examples in '.$cat['n'], self::cards(array_map(fn ($r, $k) => [self::rolePath($k), $r['t'].' resume', self::fit($r['s'], 90)], $roles, array_keys($roles)))];
        }
        $sections[] = ['tips', 'Tips for a stronger resume', self::ul(['Lead with a 2 to 3 line summary that names your role and years of experience.', 'Write each experience line as action + task + result with a number.', 'Name tools and standards exactly as job posts write them, so an '.self::ATS_DEF.' can match them.', 'Keep one page if you have under 5 years of experience.'])];
        $first = array_key_first($roles);

        return ['kind' => 'resume-examples', 'eyebrow' => 'RESUME EXAMPLES', 'title' => $cat['n'].' Resume Examples & Format', 'description' => $cat['n'].' resume examples for India: what recruiters check, who hires, sample resumes by role and tips to write your own.',
            'h1' => $cat['n'].' resume examples', 'lead' => $cat['i'],
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/'], [$cat['n'], "/resume-examples/$c/"]), 'image' => self::image('resume-examples', $cat['n'].' resume example layout with summary, experience and skills'),
            'sections' => $sections, 'sample' => $first ? self::sample($roles[$first]) : null,
            'faq' => [['What is the best resume format for '.self::lc($cat['n']).' jobs?', 'Reverse-chronological with a short summary, then experience, skills and education. Freshers put education and projects first.'], ['What should I highlight?', implode('; ', $cat['h']).'.'], ['Do I need a cover letter?', 'It helps when you apply by email or for roles that ask for one. Keep it to 3 short paragraphs.'], ['Should I use a design template?', 'For portal applications use a simple, single-column layout. Use designed templates when you send your resume directly.']],
            'related' => [['More in this field', array_filter([$roles ? ["/cover-letter-examples/$c/", $cat['n'].' cover letter examples'] : null, $first ? ["/interview-questions/$first/", $roles[$first]['t'].' interview questions'] : null])], ['Guides', self::guides('bullets', 'skills', 'ats')]]];
    }

    private static function role(string $k): array
    {
        $d = self::data();
        $r = $d['roles'][$k];
        $cat = $d['categories'][$r['c']];
        $t = $r['t'];
        $siblings = array_filter(self::rolesIn($r['c']), fn ($_, $key) => $key !== $k, ARRAY_FILTER_USE_BOTH);
        $cities = [];
        foreach ($d['cities'] as $cs => $city) {
            foreach (self::cityRoles($cs) as $slug => $cr) {
                if ($cr['role'] === $k) {
                    $cities[] = ["/resume/$cs/$slug/", "$t resume in {$city['n']}"];
                }
            }
        }
        $sections = [
            ['summary', "$t resume summary example", '<blockquote class="sp-quote">'.self::e($r['s']).'</blockquote><p>Your summary should say your role, your years of experience, your main skills and one strength. Keep it to 2 or 3 lines.</p>'],
            ['bullets', "$t experience bullet points", '<p>Write each line as an action, the task and a result. Use your own numbers.</p>'.self::ul($r['b'], 'sp-check')],
            ['skills', "Skills for a $t resume", '<p>These are the skills and keywords that often appear in '.self::e(self::lc($t)).' job posts. List the ones you can prove, using the same words as the job description so an '.self::ATS_DEF.' can match them.</p><div class="sp-chips">'.implode('', array_map(fn ($s) => '<span>'.self::e($s).'</span>', $r['k'])).'</div>'],
            ['recruiters', 'What recruiters check first', '<p>'.self::e($cat['i']).'</p>'.self::ul($cat['h'])],
            ['freshers', 'If you are a fresher', '<p>'.self::e($r['f']).'</p>'],
            ['interview', "$t interview questions", '<p>Prepare for these common questions. See all answers and tips on the <a href="/interview-questions/'.$k.'/">'.self::e($t).' interview questions</a> page.</p>'.self::ul(array_map(fn ($q) => $q[0], array_slice($r['q'], 0, 3)))],
        ];
        if ($cities) {
            $sections[] = ['cities', "$t resume by city", self::links($cities)];
        }

        return ['kind' => 'resume-example', 'eyebrow' => mb_strtoupper($cat['n']), 'title' => "$t Resume Example & Format", 'description' => "$t resume example for India with a sample summary, achievement bullet points, skills, ATS keywords and interview questions. Build yours free.",
            'h1' => "$t resume example", 'lead' => "A filled $t resume sample with a summary, experience lines, skills and the keywords recruiters search for. Use it as a guide and write your own real results.",
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/'], [$cat['n'], "/resume-examples/{$r['c']}/"], [$t, self::rolePath($k)]),
            'image' => self::image('resume-example', "$t resume example showing summary, experience and skills sections"),
            'sample' => self::sample($r), 'sections' => $sections, 'faq' => self::roleFaq($r),
            'related' => [['Next steps for this role', [["/interview-questions/$k/", "$t interview questions"], ["/cover-letter-examples/{$r['c']}/", $cat['n'].' cover letter examples'], ['/resume-templates/ats-friendly/', 'ATS-friendly resume templates']]],
                ['Similar roles', array_map(fn ($x, $key) => [self::rolePath($key), $x['t'].' resume'], array_slice($siblings, 0, 5, true), array_keys(array_slice($siblings, 0, 5, true)))],
                ['Guides', self::guides('summary', 'bullets', 'skills', 'verbs')]]];
    }

    private static function template(string $k): array
    {
        $d = self::data();
        $tp = $d['templates'][$k];
        $others = array_filter($d['templates'], fn ($_, $key) => $key !== $k, ARRAY_FILTER_USE_BOTH);
        $pick = $k === 'fresher' ? 'freshers' : ($k === 'creative' ? 'graphic-designer' : ($k === 'with-photo' ? 'hospitality' : 'software-engineer'));

        return ['kind' => 'template', 'eyebrow' => 'RESUME TEMPLATES', 'title' => $tp['n'].' Resume Templates', 'description' => $tp['n'].' resume templates: who they suit, how to use them and how to keep them ATS-friendly. Edit online with a live preview.',
            'h1' => $tp['n'].' resume templates', 'lead' => 'Best for: '.$tp['w'],
            'crumbs' => self::crumbs(['Resume templates', '/templates.html'], [$tp['n'], "/resume-templates/$k/"]), 'image' => self::image('resume-template', $tp['n'].' resume template layout preview'),
            'sections' => [['who', 'Who this template suits', '<p>'.self::e($tp['w']).'</p>'],
                ['features', 'What makes it work', self::ul($tp['u'], 'sp-check')],
                ['ats', 'Keeping it ATS-friendly', '<p>An '.self::ATS_DEF.' reads your resume before a person does. '.self::e($tp['a']).'</p>'],
                ['how', 'How to use it', self::ul(['Open the templates gallery and filter by layout.', 'Start from the design, or upload your old resume to fill it in.', 'Check your ATS-friendly score as you edit.', 'Preview free. Download a clean PDF or Word file when you are ready.']).'<p><a class="sp-text-link" href="/templates.html">Open the templates gallery</a></p>']],
            'sample' => self::sample($d['roles'][$pick]),
            'faq' => [['Is a '.self::lc($tp['n']).' resume template free?', 'You can build, edit and preview for free. A clean, watermark-free download needs a paid option.'], ['Will this template pass ATS?', 'No template can promise that every employer system reads it perfectly. Simple layouts with standard headings are the safest.'], ['Can I change the template later?', 'Yes. Your content stays when you switch designs.'], ['PDF or Word?', 'Send PDF unless the job post asks for Word. You can download both.']],
            'related' => [['Other template styles', array_map(fn ($x, $key) => ["/resume-templates/$key/", $x['n'].' templates'], $others, array_keys($others))], ['Guides', self::guides('ats', 'pdfword')]]];
    }

    private static function coverHub(): array
    {
        $cards = [];
        foreach (self::data()['categories'] as $c => $cat) {
            if (self::rolesIn($c)) {
                $cards[] = ["/cover-letter-examples/$c/", $cat['n'], 'Sample opening and what to highlight'];
            }
        }

        return ['kind' => 'cover-letter', 'eyebrow' => 'COVER LETTER EXAMPLES', 'title' => 'Cover Letter Examples by Industry', 'description' => 'Cover letter examples for Indian job seekers by industry, with a sample opening paragraph, what to highlight and a simple 3-paragraph structure.',
            'h1' => 'Cover letter examples by industry', 'lead' => 'A good cover letter is short: why this job, what you bring, and a polite close. Pick your field for a sample.',
            'crumbs' => self::crumbs(['Cover letter examples', '/cover-letter-examples/']), 'image' => self::image('cover-letter', 'Cover letter example with greeting, three paragraphs and sign-off'),
            'sections' => [['structure', 'A simple cover letter structure', self::ul(['Paragraph 1: the role you are applying for and one line on why you fit.', 'Paragraph 2: two or three results from your experience that match the job.', 'Paragraph 3: a polite close and when you can join or talk.'], 'sp-check').'<p>Many employers read the letter after an '.self::ATS_DEF.' has shortlisted your resume, so check your resume first.</p>'],
                ['fields', 'Browse by field', self::cards($cards)]],
            'faq' => [['How long should a cover letter be?', 'About 200 to 300 words, in 3 short paragraphs.'], ['Do Indian employers read cover letters?', 'Many read them for email applications and some portal roles. A short, specific letter can help you stand out.'], ['Should I write a new letter for every job?', 'Yes. Change the first and second paragraphs to match each job post.'], ['Can I send the cover letter in the email body?', 'Yes. For email applications, a short letter in the body plus your resume attached works well.']],
            'related' => [['Guides', self::guides('coverEmail', 'followup')]]];
    }

    private static function cover(string $c): array
    {
        $cat = self::data()['categories'][$c];
        $roles = self::rolesIn($c);
        $first = array_key_first($roles);
        $letter = '<figure class="sp-sample sp-letter"><div class="sp-doc"><p>Dear Hiring Manager,</p><p>'.self::e($cat['cl']).'</p><p>In my current role I '.self::e(lcfirst(rtrim($roles[$first]['b'][0], '.'))).'. I would bring the same care to your team, along with strengths in '.self::e(implode(', ', array_slice($roles[$first]['k'], 0, 3))).'.</p><p>Thank you for your time. I would be glad to talk about how I can help. I can join within 30 days.</p><p>Yours sincerely,<br>Sample Candidate</p></div><figcaption>Sample only. Write your own letter with your real experience.</figcaption></figure>';

        return ['kind' => 'cover-letter', 'eyebrow' => 'COVER LETTER EXAMPLES', 'title' => $cat['n'].' Cover Letter Examples', 'description' => $cat['n'].' cover letter example with a sample opening, what to highlight and how to match it to the job post. Write yours free.',
            'h1' => $cat['n'].' cover letter examples', 'lead' => 'What to highlight: '.implode('; ', $cat['h']).'.',
            'crumbs' => self::crumbs(['Cover letter examples', '/cover-letter-examples/'], [$cat['n'], "/cover-letter-examples/$c/"]), 'image' => self::image('cover-letter', $cat['n'].' cover letter example layout'),
            'sample' => $letter,
            'sections' => [['highlight', 'What to highlight', self::ul($cat['h'], 'sp-check')],
                ['employers', 'Who you may be writing to', self::ul($cat['e'])],
                ['tips', 'Tips', self::ul(['Name the exact role and where you saw it.', 'Pick 2 results that match the job post and give numbers.', 'Do not repeat your whole resume.', 'Keep the tone polite and simple.']).'<p>Recruiters often read the letter only after an '.self::ATS_DEF.' has shortlisted your resume, so use the job post’s key skills in both.</p>'],
                ['roles', 'Resume examples for this field', self::links(array_map(fn ($r, $k) => [self::rolePath($k), $r['t'].' resume example'], $roles, array_keys($roles)))]],
            'faq' => [['How do I start a '.self::lc($cat['n']).' cover letter?', 'Name the role and give one line on why you fit, as in the sample above.'], ['What should I highlight?', implode('; ', $cat['h']).'.'], ['Should I mention salary?', 'Only if the job post asks. Otherwise discuss it later in the process.'], ['How do I end the letter?', 'Thank them, say you would like to talk, and mention your notice period if relevant.']],
            'related' => [['Related', [["/resume-examples/$c/", $cat['n'].' resume examples'], ["/interview-questions/$first/", $roles[$first]['t'].' interview questions']]], ['Guides', self::guides('coverEmail', 'followup')]]];
    }

    private static function interviewHub(): array
    {
        $d = self::data();
        $groups = '';
        foreach ($d['categories'] as $c => $cat) {
            $roles = self::rolesIn($c);
            if ($roles) {
                $groups .= '<h3>'.self::e($cat['n']).'</h3>'.self::links(array_map(fn ($r, $k) => ["/interview-questions/$k/", $r['t'].' interview questions'], $roles, array_keys($roles)));
            }
        }

        return ['kind' => 'interview', 'eyebrow' => 'INTERVIEW QUESTIONS', 'title' => 'Interview Questions & Answers by Role', 'description' => 'Common interview questions with tips on how to answer, by job role, for freshers and experienced candidates in India.',
            'h1' => 'Interview questions by role', 'lead' => 'Each page lists common questions for the role and how to answer them in your own words.',
            'crumbs' => self::crumbs(['Interview questions', '/interview-questions/']), 'image' => self::image('interview', 'Interview question cards with answer tips'),
            'sections' => [['general', 'Questions you will hear in most interviews', self::ul(['Tell me about yourself.', 'Why do you want this job?', 'What are your strengths and weaknesses?', 'Tell me about a challenge you handled.', 'What are your salary expectations?']).'<p>Interviews come after your resume clears the '.self::ATS_DEF.' and a recruiter’s first read, so prepare both.</p>'],
                ['roles', 'Browse by role', $groups]],
            'faq' => [['How should I prepare for an interview?', 'Read the job post, match 3 stories from your resume to it, practise answers out loud and prepare 2 questions to ask.'], ['What is the STAR method?', 'Situation, Task, Action, Result: a simple way to answer "tell me about a time" questions.'], ['How long should an answer be?', 'Usually 1 to 2 minutes. Stop when you have given the result.'], ['Should I memorise answers?', 'No. Know your stories and key numbers, then speak naturally.']],
            'related' => [['Guides', self::guides('tmay', 'star', 'ask', 'salary', 'thanks')]]];
    }

    private static function interview(string $k): array
    {
        $r = self::data()['roles'][$k];
        $t = $r['t'];
        $qa = '<ol class="sp-qa">'.implode('', array_map(fn ($q) => '<li><h3>'.self::e($q[0]).'</h3><p><b>How to answer:</b> '.self::e($q[1]).'</p></li>', $r['q'])).'</ol>';
        $common = '<ol class="sp-qa"><li><h3>Tell me about yourself.</h3><p><b>How to answer:</b> In about a minute: your current role or degree, 1 or 2 results relevant to this '.self::e(self::lc($t)).' role, and why you want this job.</p></li><li><h3>Why should we hire you?</h3><p><b>How to answer:</b> Match 3 of your strengths to the job post, for example '.self::e(implode(', ', array_slice($r['k'], 0, 3))).', with proof.</p></li><li><h3>What is your notice period?</h3><p><b>How to answer:</b> Give the exact period and whether early release is possible.</p></li></ol>';

        return ['kind' => 'interview', 'eyebrow' => 'INTERVIEW QUESTIONS', 'title' => "$t Interview Questions & Answers", 'description' => "$t interview questions with tips on how to answer, plus common HR questions. Prepare with examples from your own resume.",
            'h1' => "$t interview questions and answers", 'lead' => "Common questions in $t interviews and how to answer them. Use examples from your own work, not memorised lines.",
            'crumbs' => self::crumbs(['Interview questions', '/interview-questions/'], [$t, "/interview-questions/$k/"]), 'image' => self::image('interview', "$t interview questions with answer tips"),
            'sections' => [['role-questions', "Role questions for $t", $qa], ['hr-questions', 'Common HR questions', $common],
                ['prepare', 'How to prepare', self::ul(['Read the job post and list the skills it asks for.', 'Prepare 3 short stories from your resume using the STAR method.', 'Know the numbers on your resume and how you got them.', 'Prepare 2 questions to ask the interviewer.'])],
                ['skills', 'Skills interviewers often test', '<div class="sp-chips">'.implode('', array_map(fn ($s) => '<span>'.self::e($s).'</span>', $r['k'])).'</div>']],
            'faq' => [["What questions are asked in a $t interview?", 'Role questions about your skills and past work, plus HR questions such as "Tell me about yourself" and notice period. See the list above.'], ['How do I answer "Tell me about yourself"?', 'Give your current role or degree, one or two relevant results, and why you want this job, in about a minute.'], ["How should a fresher prepare for a $t interview?", $r['f']], ['What should I bring to the interview?', 'Copies of your resume, ID, certificates if asked, and a short list of questions for them.']],
            'related' => [['For this role', [[self::rolePath($k), "$t resume example"], ['/cover-letter-examples/'.$r['c'].'/', 'Cover letter examples']]], ['Guides', self::guides('tmay', 'star', 'ask', 'thanks')]]];
    }

    private static function jobSources(string $where): array
    {
        return ['Company career pages of employers in '.$where, 'Campus placement cells and alumni networks', 'Referrals from people already working in your field', 'The National Career Service portal run by the Government of India', 'Job portals and professional networking sites, using '.$where.' as the location filter'];
    }

    private static function india(): array
    {
        $d = self::data();
        $t1 = $t2 = [];
        foreach ($d['cities'] as $c => $city) {
            ${'t'.$city['tier']}[] = ["/resume-builder/$c/", 'Resume builder '.$city['n']];
        }

        return ['kind' => 'place', 'eyebrow' => 'RESUME BUILDER INDIA', 'title' => 'Resume Builder for India: Free to Start', 'description' => 'Build an ATS-friendly resume for jobs in India. Pick your state or city for local job market tips, role examples and where to find jobs.',
            'h1' => 'Resume builder for jobs in India', 'lead' => 'Build a resume that Indian recruiters and job portals can read easily. Start free, then choose your state or city for local tips.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/']), 'image' => self::image('india', 'Map-style card of Indian cities covered by CVTara resume guides'),
            'sections' => [['format', 'Resume format that works in India', self::ul(['Name, phone, email, city and a professional profile link at the top. Date of birth, religion and marital status are not needed.', 'A 2 to 3 line summary naming your role and years of experience.', 'Experience, latest first, with results in numbers.', 'Skills that match the job post, so an '.self::ATS_DEF.' can find them.', 'Education with year and score if it helps you.'], 'sp-check')],
                ['states', 'Choose your state', self::cards(array_map(fn ($s, $k) => ["/resume-builder/$k/", $s['n'], implode(', ', array_slice($s['ind'], 0, 2))], $d['states'], array_keys($d['states'])))],
                ['tier1', 'Major cities', self::links($t1)], ['tier2', 'Growing cities', self::links($t2)]],
            'faq' => [['Is the resume builder free?', 'Building, editing and previewing is free. A clean, watermark-free PDF or Word download needs a paid option.'], ['Should I add a photo on my resume in India?', 'Usually no, unless the job post asks for one.'], ['Is a CV the same as a resume in India?', 'Most Indian employers use the words the same way. A long academic CV is different.'], ['How many pages should my resume be?', 'One page for freshers and early careers, up to two for experienced professionals.']],
            'related' => [['Start here', [['/resume-examples/', 'Resume examples by field'], ['/resume-templates/ats-friendly/', 'ATS-friendly templates'], ['/interview-questions/', 'Interview questions']]], ['Guides', self::guides('ats', 'fresher', 'ctc')]]];
    }

    private static function state(string $s): array
    {
        $d = self::data();
        $st = $d['states'][$s];
        $cities = array_map(fn ($c) => ["/resume-builder/$c/", 'Resume builder '.$d['cities'][$c]['n']], $st['cities']);

        return ['kind' => 'place', 'eyebrow' => mb_strtoupper($st['n']), 'title' => 'Resume Builder '.$st['n'], 'description' => 'Resume tips for jobs in '.$st['n'].': main industries, government job route, city guides and a free ATS-friendly resume builder.',
            'h1' => 'Resume builder for jobs in '.$st['n'], 'lead' => 'Main industries: '.implode('; ', $st['ind']).'. Choose a city below for local tips and role examples.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/'], [$st['n'], "/resume-builder/$s/"]), 'image' => self::image('india', 'Resume guide card for '.$st['n']),
            'sections' => [['industries', 'Industries that hire in '.$st['n'], self::ul($st['ind'])],
                ['cities', 'City guides', self::links($cities)],
                ['government', 'Government jobs in '.$st['n'], '<p>State government posts are usually filled through the '.self::e($st['psc']).' and other state recruitment boards. Always apply in the format given in the official notification. A separate resume helps for contract roles, PSUs and interviews.</p><p><a class="sp-text-link" href="/resume/'.$s.'/government-jobs/">Government jobs resume for '.self::e($st['n']).'</a></p>'],
                ['tips', 'Resume tips for '.$st['n'], self::ul(['Use the local city as your location so recruiters filtering by place can find you.', 'List the languages you speak and your level in each.', 'Keep a simple, single-column layout for portal applications.'])],
                ['sources', 'Where to find jobs', self::ul(self::jobSources($st['n']))]],
            'faq' => [['What resume format works for jobs in '.$st['n'].'?', 'A reverse-chronological resume with a short summary, results in numbers and skills that match the job post.'], ['Do I need a different resume for government jobs?', 'For exam-based posts, follow the official notification format. For contract roles and interviews, a clear resume with exact dates helps.'], ['Should I mention local languages?', 'Yes, list each language and your level. It helps in customer-facing and field roles.'], ['Can I build my resume for free?', 'Yes. Building and previewing is free.']],
            'related' => [['Related', [['/resume-builder/india/', 'Resume builder India'], ["/resume/$s/government-jobs/", 'Government jobs resume'], ['/resume-examples/government/', 'Government resume examples']]], ['Guides', self::guides('ats', 'scams')]]];
    }

    private static function stateGov(string $s): array
    {
        $d = self::data();
        $st = $d['states'][$s];
        $r = $d['roles']['government-jobs'];

        return ['kind' => 'place-role', 'eyebrow' => mb_strtoupper($st['n']).' · GOVERNMENT JOBS', 'title' => 'Government Jobs Resume '.$st['n'], 'description' => 'Resume format for government and PSU jobs in '.$st['n'].': how state recruitment works, what to include and a sample you can follow.',
            'h1' => 'Government jobs resume for '.$st['n'], 'lead' => 'Most state posts are filled through the '.$st['psc'].' and state recruitment boards, using the format in each official notification. Use this resume for contract roles, PSUs, interviews and document checks.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/'], [$st['n'], "/resume-builder/$s/"], ['Government jobs', "/resume/$s/government-jobs/"]), 'image' => self::image('resume-example', 'Government jobs resume sample for '.$st['n']),
            'sample' => self::sample($r, $d['cities'][$st['cities'][0]]['n']),
            'sections' => [['how', 'How state recruitment usually works', self::ul(['Read the official notification on the commission or board website.', 'Apply online in the given format before the last date.', 'Clear the written exam, skill test or interview as listed.', 'Bring original documents for verification.'])],
                ['include', 'What to include on your resume', self::ul(['Exact qualifications with year, board or university and marks', 'Exact dates for every job', 'Duties in plain words', 'Computer skills and typing speed if relevant', 'Languages, including '.$st['n'].'’s main language if you speak it'], 'sp-check')],
                ['skills', 'Useful skills', '<div class="sp-chips">'.implode('', array_map(fn ($x) => '<span>'.self::e($x).'</span>', $r['k'])).'</div>']],
            'faq' => [['Do government jobs accept resumes?', 'Exam-based posts use the application form in the notification. Contract posts, PSUs and some interviews ask for a resume.'], ['What format should a government resume use?', 'Simple and formal, with exact dates and qualifications. Avoid design elements.'], ['Should I add a photo?', 'Only if the notification or employer asks for one.'], ['Where do I check official notices?', 'On the official website of the '.$st['psc'].' or the relevant recruitment board. Do not pay anyone who promises a government job.']],
            'related' => [['Related', [["/resume-builder/$s/", 'Resume builder '.$st['n']], ['/resume-examples/government/government-jobs/', 'Government jobs resume example'], ['/interview-questions/government-jobs/', 'Government job interview questions']]], ['Guides', self::guides('scams', 'ats')]]];
    }

    private static function city(string $c): array
    {
        $d = self::data();
        $city = $d['cities'][$c];
        $st = self::stateOf($c);
        $stName = $st ? self::stateName($st) : $city['st'];
        $roles = [];
        foreach (self::cityRoles($c) as $slug => $cr) {
            $roles[] = ["/resume/$c/$slug/", ($slug === 'freshers' ? 'Freshers' : $d['roles'][$cr['role']]['t']).' resume in '.$city['n'], $cr['note'] ?: self::fit($d['roles'][$cr['role']]['s'], 80)];
        }
        $near = [];
        foreach ($d['cities'] as $k => $o) {
            if ($k !== $c && ($o['st'] === $city['st'] || ($o['tier'] === $city['tier'] && count($near) < 6))) {
                $near[] = ["/resume-builder/$k/", 'Resume builder '.$o['n']];
            }
        }
        $near = array_slice($near, 0, 8);
        $crumbs = [['Resume builder India', '/resume-builder/india/']];
        if ($st) {
            $crumbs[] = [$stName, "/resume-builder/$st/"];
        }
        $crumbs[] = [$city['n'], "/resume-builder/$c/"];

        return ['kind' => 'place', 'eyebrow' => mb_strtoupper($city['n']).', '.mb_strtoupper($stName), 'title' => 'Resume Builder '.$city['n'].': Local Job Tips', 'description' => 'Build a resume for jobs in '.$city['n'].'. Local industries, in-demand roles, resume tips and where to find jobs, with a free ATS-friendly builder.',
            'h1' => 'Resume builder for jobs in '.$city['n'], 'lead' => $city['n'].' hires across '.implode(', ', array_map(fn ($x) => lcfirst($x), array_slice($city['ind'], 0, 3))).'. Here is how to shape your resume for local employers.',
            'crumbs' => self::crumbs(...$crumbs), 'image' => self::image('city', 'Resume guide card for jobs in '.$city['n']),
            'sections' => [['market', 'The '.$city['n'].' job market', '<p>Employer types you will meet in '.self::e($city['n']).':</p>'.self::ul($city['ind'])],
                ['roles', 'In-demand roles and resume examples', self::cards($roles)],
                ['tips', 'Resume tips for '.$city['n'], self::ul(array_merge($city['tips'], ['Write "'.$city['n'].', '.$stName.'" as your location so recruiters filtering by city find you.', 'Use a single-column layout with standard headings so an '.self::ATS_DEF.' can read it.']), 'sp-check')],
                ['sources', 'Where to find jobs in '.$city['n'], self::ul(self::jobSources($city['n']))],
                ['nearby', 'Other city guides', self::links($near)]],
            'faq' => [['What is the best resume format for jobs in '.$city['n'].'?', 'Reverse-chronological with a short summary, results in numbers, and skills that match the job post. Freshers lead with education and projects.'], ['Which industries hire in '.$city['n'].'?', implode('; ', $city['ind']).'.'], ['Should I put '.$city['n'].' on my resume?', 'Yes. Add your city and state near your contact details. If you are relocating, say so and when.'], ['Where can I find jobs in '.$city['n'].'?', 'Company career pages, campus placement cells, referrals, the National Career Service portal and job portals with a location filter.'], ['Is the resume builder free?', 'Building and previewing is free. A clean download needs a paid option.']],
            'related' => array_values(array_filter([['Nearby and related', array_values(array_filter([$st ? ["/resume-builder/$st/", 'Resume builder '.$stName] : null, ['/resume-builder/india/', 'Resume builder India'], ["/resume/$c/freshers/", 'Freshers resume in '.$city['n']]]))], ['Guides', self::guides('ats', 'col', 'ctc')]]))];
    }

    private static function cityRole(string $c, string $slug): array
    {
        $d = self::data();
        $city = $d['cities'][$c];
        $cr = self::cityRoles($c)[$slug];
        $r = $d['roles'][$cr['role']];
        $t = $slug === 'freshers' ? 'Freshers' : $r['t'];
        $label = $slug === 'freshers' ? 'Resume for Freshers in '.$city['n'] : "$t Resume in {$city['n']}";
        $st = self::stateOf($c);
        $stName = $st ? self::stateName($st) : $city['st'];
        $others = [];
        foreach ($d['cities'] as $k => $o) {
            if ($k !== $c && isset(self::cityRoles($k)[$slug])) {
                $others[] = ["/resume/$k/$slug/", "$t resume in {$o['n']}"];
            }
        }
        $note = $cr['note'] ?: ($slug === 'freshers'
            ? 'Freshers in '.$city['n'].' compete for entry roles in '.lcfirst($city['ind'][0]).' and '.lcfirst($city['ind'][1] ?? $city['ind'][0]).'. A one-page resume that shows projects, internships and skills gets read faster.'
            : self::lc($t).' roles in '.$city['n'].' come from employers such as '.lcfirst($city['ind'][0]).' and '.lcfirst($city['ind'][1] ?? $city['ind'][0]).'. Show the local market knowledge and languages that help you serve them.');
        $crumbs = [['Resume builder India', '/resume-builder/india/']];
        if ($st) {
            $crumbs[] = [$stName, "/resume-builder/$st/"];
        }
        $crumbs[] = [$city['n'], "/resume-builder/$c/"];
        $crumbs[] = [$t, "/resume/$c/$slug/"];

        return ['kind' => 'place-role', 'eyebrow' => mb_strtoupper($city['n']).' · '.mb_strtoupper($t), 'title' => $label, 'description' => "$t resume for jobs in {$city['n']}: local hiring notes, a filled sample, skills recruiters look for, tips and interview questions. Build yours free.",
            'h1' => $slug === 'freshers' ? 'Resume for freshers in '.$city['n'] : "$t resume for jobs in {$city['n']}", 'lead' => ucfirst($note),
            'crumbs' => self::crumbs(...$crumbs), 'image' => self::image('city', "$t resume guide for {$city['n']}"),
            'sample' => self::sample($r, $city['n']),
            'sections' => [['local', "Hiring for $t roles in {$city['n']}", '<p>'.self::e(ucfirst($note)).'</p><p>Employer types in the city:</p>'.self::ul($city['ind'])],
                ['local-tips', 'Local resume tips', self::ul(array_merge($city['tips'], ['Write "'.$city['n'].', '.$stName.'" as your location. If you are moving to the city, add "Relocating to '.$city['n'].'" with a month.']), 'sp-check')],
                ['skills', "Skills for a $t resume", '<p>Match these to the job post, using the same words so an '.self::ATS_DEF.' can find them.</p><div class="sp-chips">'.implode('', array_map(fn ($s) => '<span>'.self::e($s).'</span>', $r['k'])).'</div>'],
                ['bullets', 'Experience lines that work', self::ul($r['b'], 'sp-check').'<p>Replace the numbers with your own real results.</p>'],
                ['interview', 'Interview questions to prepare', self::ul(array_map(fn ($q) => $q[0], array_slice($r['q'], 0, 3))).'<p><a class="sp-text-link" href="/interview-questions/'.$cr['role'].'/">All '.self::e($r['t']).' interview questions with answer tips</a></p>'],
                ['sources', 'Where to find '.self::e(self::lc($t)).' jobs in '.$city['n'], self::ul(self::jobSources($city['n']))]],
            'faq' => [["What should a $t resume for {$city['n']} include?", 'Your city and state, a short summary, experience with numbers, skills matching the job post and education. Recruiters in the city often filter by location.'],
                ["Which skills matter for $t jobs?", implode(', ', array_slice($r['k'], 0, 6)).'.'],
                ['How do I apply for jobs in '.$city['n'].' from another city?', 'Add "Relocating to '.$city['n'].'" with a month near your contact details and say it in your cover letter.'],
                ["Can freshers apply for $t jobs in {$city['n']}?", $r['f']],
                ['Is the resume builder free?', 'Building and previewing is free. A clean download needs a paid option.']],
            'related' => [['For this role', [[self::rolePath($cr['role']), $r['t'].' resume example'], ['/interview-questions/'.$cr['role'].'/', $r['t'].' interview questions'], ['/cover-letter-examples/'.$r['c'].'/', 'Cover letter examples']]],
                ['Same role in other cities', array_slice($others, 0, 2)],
                ['More in '.$city['n'], [["/resume-builder/$c/", 'Resume builder '.$city['n']]]]]];
    }

    private static function wordsHub(): array
    {
        $w = self::data()['words'];

        return ['kind' => 'words', 'eyebrow' => 'RESUME WORDS', 'title' => 'Resume Words: Better Synonyms to Use', 'description' => 'Stronger words for your resume: alternatives to overused words like learn, help, manage and responsible for, with before and after examples.',
            'h1' => 'Better words for your resume', 'lead' => 'Overused words make strong work sound ordinary. Pick a word to see alternatives and a before-and-after example.',
            'crumbs' => self::crumbs(['Resume words', '/resume-words/']), 'image' => self::image('words', 'Resume word swaps from weak to strong'),
            'sections' => [['words', 'Choose a word', self::cards(array_map(fn ($x, $k) => ["/resume-words/$k/", 'Another word for '.mb_strtolower($x['w']), implode(', ', array_slice($x['s'], 0, 3))], $w, array_keys($w)))],
                ['rule', 'The rule that matters more than the word', '<p>A better verb helps, but a result helps more. Write action + task + result: "Cut report time by 10 hours a week by automating 12 Excel reports" says more than any single word.</p><p>An '.self::ATS_DEF.' mostly matches skills and job titles. Strong verbs help the person who reads your resume next.</p>']],
            'faq' => [['What are action verbs on a resume?', 'Words at the start of a line that show what you did, such as led, built or reduced.'], ['Should every line start with a different verb?', 'Try to vary them, but clarity matters more than variety.'], ['Is it fine to use the same words as the job post?', 'Yes, for skills and job titles. It helps matching.'], ['Which words should I avoid?', 'Vague claims such as hard-working or passionate without proof. Show it with a result instead.']],
            'related' => [['Guides', self::guides('verbs', 'bullets', 'summary')]]];
    }

    private static function word(string $k): array
    {
        $all = self::data()['words'];
        $w = $all[$k];
        $lw = mb_strtolower($w['w']);
        $others = array_slice(array_filter($all, fn ($_, $key) => $key !== $k, ARRAY_FILTER_USE_BOTH), 0, 8, true);

        return ['kind' => 'words', 'eyebrow' => 'RESUME WORDS', 'title' => "Another Word for \"$w[w]\" on a Resume", 'description' => "Synonyms for \"$lw\" on a resume, with a before-and-after example. Replace weak words with specific actions and results.",
            'h1' => "Another word for “{$lw}” on a resume", 'lead' => 'Meaning on a resume: '.$w['m'],
            'crumbs' => self::crumbs(['Resume words', '/resume-words/'], [$w['w'], "/resume-words/$k/"]), 'image' => self::image('words', "Alternatives to the word $lw on a resume"),
            'sections' => [['synonyms', "Words to use instead of “{$lw}”", '<div class="sp-chips">'.implode('', array_map(fn ($s) => '<span>'.self::e($s).'</span>', $w['s'])).'</div>'],
                ['example', 'Before and after', '<div class="sp-before-after"><p><span>Before</span>'.self::e($w['ex'][0]).'</p><p><span>After</span>'.self::e($w['ex'][1]).'</p></div><p>The better line names the task and gives a result. Use your own real numbers.</p>'],
                ['how', 'How to choose the right word', self::ul(['Pick the word that is most accurate for what you did.', 'Put it at the start of the line.', 'Follow it with the task and a measurable result.', 'Do not use the same word more than twice on one page.'])]],
            'faq' => [["Is “{$lw}” a bad word for a resume?", 'Not bad, but often vague. A more specific word plus a result reads stronger.'], ['How many action verbs should I use?', 'One at the start of each bullet point.'], ['Can I use these words in a cover letter?', 'Yes. The same rule applies: be specific and give proof.'], ['Will these words help with ATS?', 'ATS matching depends mostly on skills and job titles. Strong verbs help the person who reads your resume next.']],
            'related' => [['More resume words', array_map(fn ($x, $key) => ["/resume-words/$key/", 'Another word for '.mb_strtolower($x['w'])], $others, array_keys($others))], ['Guides', self::guides('verbs', 'bullets')]]];
    }

    private static function atsChecker(): array
    {
        return ['kind' => 'tool', 'eyebrow' => 'FREE ATS CHECK', 'title' => 'Free ATS Resume Checker for India', 'description' => 'Check how well an applicant tracking system can read your resume, and how it matches a job description. See missing keywords and what to fix.',
            'h1' => 'Free ATS resume checker', 'lead' => 'An '.self::ATS_DEF.' reads your resume before a recruiter does. CVTara checks how readable your resume is and how well it matches a job post, then shows what to fix.',
            'crumbs' => self::crumbs(['ATS checker', '/ats-checker/']), 'image' => self::image('ats', 'ATS resume check report with matched and missing keywords'),
            'sections' => [['what', 'What the check looks at', self::ul(['Standard section names such as Experience, Education and Skills', 'Readable text (not text inside images)', 'Contact details in the right place', 'Keywords and skills from the job description: matched, partly matched and missing', 'Simple fixes you can make in the builder'], 'sp-check')],
                ['how', 'How it works', '<ol class="sp-steps"><li><b>Add your resume.</b> Upload a PDF or Word file, or build one from a template.</li><li><b>Add the job.</b> Paste the job description or add the job link.</li><li><b>See the report.</b> A match estimate, missing keywords and the top fixes.</li><li><b>Fix and recheck.</b> Edit in the builder and run the check again.</li></ol>'],
                ['limits', 'What no checker can promise', '<p>Every employer configures their system differently, so no tool can guarantee a shortlist. The check shows clear, explainable gaps so you can fix them before you apply.</p>']],
            'faq' => [['Is the ATS check free?', 'Free accounts get a few checks every day. Plans include more scans.'], ['What score is good?', 'Aim to fix every missing must-have skill you really have. A higher match helps, but honesty matters more than a number.'], ['Does a two-column resume fail ATS?', 'Not always, but some systems read columns in the wrong order. A single column is safest.'], ['Which file type should I upload?', 'A text-based PDF or Word file. Scanned images cannot be read.']],
            'related' => [['Related', [['/resume-templates/ats-friendly/', 'ATS-friendly templates'], ['/resume-examples/', 'Resume examples']]], ['Guides', self::guides('ats', 'skills', 'pdfword')]]];
    }

    // ---------------------------------------------------------------- output

    /** JSON-LD blocks: BreadcrumbList and FAQPage on every page, plus an ItemList on hubs. No LocalBusiness markup. */
    public static function schema(array $p, string $base): array
    {
        $ld = [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $base.$c[1]], $p['crumbs'], array_keys($p['crumbs']))]];
        if (! empty($p['faq'])) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $p['faq'])];
        }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $p['h1'], 'description' => $p['description'], 'url' => $base.$p['path'], 'image' => $base.$p['image'][0], 'inLanguage' => 'en-IN', 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara', 'logo' => $base.'/assets/brand/cvtara-logo.webp']];

        return $ld;
    }

    public static function render(array $p): string
    {
        return view('seo.page', ['p' => $p])->render();
    }
}
