<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DB;

final class ContentCatalog
{
    public static function tools(): array
    {
        return [
            ['take-home-salary-calculator', 'Take-Home Salary Calculator', 'Income & tax', 'Estimate monthly in-hand pay with visible deductions and assumptions.'],
            ['ctc-to-in-hand-salary-calculator', 'CTC to In-Hand Salary Calculator', 'Income & tax', 'Translate employer CTC into an estimated monthly bank credit.'],
            ['salary-breakup-calculator', 'Salary Breakup Calculator', 'Income & tax', 'Model basic, HRA, employer costs, variable pay and deductions.'],
            ['gratuity-calculator-online', 'Gratuity Calculator Online', 'Exit & benefits', 'Calculate an illustrative gratuity amount from wages and service.'],
            ['notice-period-buyout-calculator', 'Notice Period Buyout Calculator', 'Exit & benefits', 'Estimate notice recovery from the salary basis stated in your contract.'],
            ['education-loan-calculator-roi', 'Education Loan ROI Calculator', 'Career growth', 'Model course cost, loan interest, salary uplift and payback time.'],
            ['salary-increment-percentage-calculator', 'Salary Increment Percentage Calculator', 'Career growth', 'Calculate raise percentage, annual difference and monthly change.'],
            ['expected-salary-interview-calculator', 'Expected Salary Calculator for Interviews', 'Salary resources', 'Build a defensible minimum, target and stretch range.'],
            ['remote-work-savings-calculator', 'Remote Work Savings Calculator', 'Work choices', 'Compare monthly cash and time costs across remote, hybrid and office work.'],
            ['contractor-vs-full-time-salary-calculator', 'Contractor vs Full-Time Salary Calculator', 'Work choices', 'Compare contract income with salary, paid leave and employer benefits.'],
            ['sip-calculator-monthly-income', 'SIP Calculator for Monthly Income', 'Wealth planning', 'Project a monthly investment corpus with editable return assumptions.'],
            ['cost-of-living-calculator-by-city', 'Cost of Living Calculator by City', 'Work choices', 'Compare two cities using your own budget and current cost indices.'],
        ];
    }

    public static function guides(): array
    {
        return [
            ['resume/ats-friendly-format-india', 'ATS-Friendly Resume Format for India', 'Resume formatting', 'Use a text-first structure recruiters and software can parse.'],
            ['resume/achievements-bullet-points', 'Key Achievement Examples for a Resume', 'Resume formatting', 'Turn duties into action, context and verifiable outcomes.'],
            ['resume/no-experience-format', 'How to Make a Resume With No Experience', 'Resume formatting', 'Use projects, coursework, internships and campus evidence effectively.'],
            ['resume/professional-summary-guide', 'How to Write a Professional Summary', 'Resume formatting', 'Create a focused summary from role, experience and evidence.'],
            ['resume/action-verbs-list', 'Resume Action Verbs and Power Words', 'Resume formatting', 'Choose precise verbs and support them with real context.'],
            ['jobs/follow-up-email-template', 'Follow-Up Email After a Job Application', 'Job search', 'Send one concise, useful follow-up after a reasonable interval.'],
            ['jobs/application-tracker-method', 'How to Track Job Applications', 'Job search', 'Build a simple pipeline with stages, dates and next actions.'],
            ['salary/asking-for-raise-script', 'How to Ask Your Boss for a Raise', 'Salary & offers', 'Prepare evidence, market context and a clear target.'],
            ['interview/star-method-examples', 'STAR Method Examples and Template', 'Interview preparation', 'Build factual situation, task, action and result stories.'],
            ['interview/tell-me-about-yourself', 'How to Answer Tell Me About Yourself', 'Interview preparation', 'Use a present–past–future structure with evidence.'],
        ];
    }

    public static function find(string $type, string $slug): ?array
    {
        $list = $type === 'Calculator' ? self::tools() : self::guides();
        foreach ($list as $row) {
            if ($row[0] === $slug) {
                return ['slug' => $row[0], 'title' => $row[1], 'category' => $row[2], 'description' => $row[3], 'type' => $type];
            }
        }
        $page = DB::fetch('SELECT * FROM pages WHERE slug = ? AND type = ? AND published = 1', [$slug, $type]);
        return $page ?: null;
    }

    public static function related(array $item): array
    {
        $pool = $item['type'] === 'Calculator' ? self::guides() : self::tools();
        return array_slice($pool, 0, 3);
    }

    public static function plans(): array
    {
        $defaults = [
            'd30' => ['name' => '30-day job kit', 'price' => 29900, 'fresherPrice' => 19900, 'days' => 30, 'kits' => 1, 'credits' => 5],
            'd60' => ['name' => '60-day job kit', 'price' => 49900, 'fresherPrice' => 34900, 'days' => 60, 'kits' => 2, 'credits' => 10],
            'd90' => ['name' => '90-day job kit', 'price' => 69900, 'fresherPrice' => 49900, 'days' => 90, 'kits' => 3, 'credits' => 20],
        ];
        try {
            $saved = \App\Support\Settings::cached('plans', null);
            if (is_array($saved) && isset($saved['d30']['price'], $saved['d30']['fresherPrice'], $saved['d60'], $saved['d90'])) {
                return array_replace_recursive($defaults, $saved);
            }
        } catch (\Throwable) {
        }
        return $defaults;
    }
}
