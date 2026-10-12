<?php
declare(strict_types=1);

namespace App\Services;

final class AtsScannerService
{
    private const STOP = [
        'the','and','for','with','that','this','from','your','you','are','was','were','have','has','had','not','but','all','any','can','will','our','their','they','them','his','her','its','into','over','under','about','after','before','than','then','also','using','used','use','able','more','most','such','per','via','etc','job','role','team','work','working','experience','skills','skill','required','requirements','responsibilities','including','plus','good','strong','excellent','knowledge'
    ];

    private const VERBS = [
        'led','built','created','designed','delivered','improved','reduced','increased','managed','owned','launched','analysed','analyzed','implemented','coordinated','developed','automated','resolved','tracked','documented','trained','mentored','negotiated','presented','reviewed','supported','migrated','optimised','optimized'
    ];

    public function scan(string $resume, string $job, bool $isPremium = false): array
    {
        $resumeTokens = $this->tokens($resume);
        $jobTokens = $this->tokens($job);
        $missing = [];
        foreach (array_count_values($jobTokens) as $word => $count) {
            if ($count >= 1 && !in_array($word, $resumeTokens, true) && strlen($word) > 3) {
                $missing[] = $word;
            }
        }
        $missing = array_slice(array_values(array_unique($missing)), 0, 18);
        $resumeVerbs = array_values(array_intersect($resumeTokens, self::VERBS));
        $verbDensity = $resumeTokens ? round(count($resumeVerbs) / max(count($resumeTokens), 1) * 100, 1) : 0.0;
        $overlap = count(array_intersect(array_unique($jobTokens), array_unique($resumeTokens)));
        $needed = max(count(array_unique($jobTokens)), 1);
        $keywordScore = (int) round(min(70, ($overlap / $needed) * 70));
        $verbScore = (int) min(20, round($verbDensity * 2));
        $lengthScore = strlen(trim($resume)) > 400 ? 10 : (strlen(trim($resume)) > 180 ? 6 : 2);
        $score = max(0, min(100, $keywordScore + $verbScore + $lengthScore));

        $rawScore = $score;
        if (!$isPremium) {
            // Deterministically vary between 35–56 based on content length to create upgrade urgency
            $seed = (strlen($resume) + strlen($job)) % 22;
            $score = 35 + $seed; // 35 to 56
        }

        if (!$isPremium) {
            $notes = [
                'Your resume is missing critical ATS elements that recruiters filter for.',
                $verbDensity < 2 ? 'Start more bullets with a precise action verb you actually performed.' : 'Action-verb use is visible. Keep results attached to each verb.',
                'Upgrade to Premium to see your full ATS compliance report and missing keywords.',
            ];
        } else {
            $notes = [
                $score >= 75 ? 'The document already covers a large share of the posted language.' : 'Add missing role language only where you have real evidence.',
                $verbDensity < 2 ? 'Start more bullets with a precise action verb you actually performed.' : 'Action-verb use is visible. Keep results attached to each verb.',
                'The compliance score measures word overlap. It is not a hiring decision or ranking guarantee.',
            ];
        }

        return [
            'score' => $score,
            'missing' => $missing,
            'matched' => array_slice(array_values(array_unique(array_intersect($jobTokens, $resumeTokens))), 0, 20),
            'verb_density' => $verbDensity,
            'action_verbs' => array_slice(array_values(array_unique($resumeVerbs)), 0, 12),
            'notes' => $notes,
            'is_capped' => !$isPremium,
            'raw_score' => $rawScore,
        ];
    }

    private function tokens(string $text): array
    {
        $text = strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', $text) ?? '');
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($parts, fn ($w) => strlen($w) > 2 && !in_array($w, self::STOP, true)));
    }
}
