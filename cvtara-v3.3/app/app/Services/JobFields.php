<?php

namespace App\Services;

/**
 * Canonical job-description fields (3.56.0, V7 §13, AT-11): what CVTara understood from a posting, shown to the customer
 * as "Is this the right job?" before the ATS check uses a scan. Pay is shown only when the posting states it. Nothing is
 * guessed from outside the posting; a field that is not found stays empty.
 */
class JobFields
{
    public static function read(string $text, string $title = '', string $company = '', string $url = '', array $details = []): array
    {
        $t = AtsEngine::clean($text);
        if ($title === '' || $company === '') {
            [$gt, $gc] = AtsEngine::headline($t);
            $title = $title ?: $gt;
            $company = $company ?: $gc;
        }
        $find = fn (string $re) => preg_match($re, $t, $m) ? trim($m[1] ?? $m[0]) : '';
        $location = (string) ($details['location'] ?? '') ?: $find('/(?:location|based in|job location|work location)\s*[:\-–]\s*([^\n]{2,80})/iu');
        if ($location === '' && preg_match('/\b(Bengaluru|Bangalore|Mumbai|Pune|Hyderabad|Chennai|Delhi|New Delhi|Gurugram|Gurgaon|Noida|Kolkata|Ahmedabad|Kochi|Coimbatore|Jaipur|Indore|Chandigarh|Remote)\b/u', $t, $m)) {
            $location = $m[1];
        }
        $type = (string) ($details['employmentType'] ?? '') ?: (preg_match('/\b(full[- ]time|part[- ]time|contract(?:ual)?|internship|freelance|temporary)\b/iu', $t, $m) ? ucfirst(strtolower(str_replace(' ', '-', $m[1]))) : '');
        $exp = (string) ($details['experience'] ?? '') ?: $find('/(\d{1,2}\s*(?:\+|to|-|–)?\s*\d{0,2}\s*(?:\+\s*)?(?:years?|yrs?))(?:\s+of)?\s+(?:relevant\s+|work\s+|total\s+)?experience/iu');
        $seniority = preg_match('/\b(intern|trainee|fresher|entry[- ]level|junior|associate|senior|lead|principal|manager|head|director)\b/iu', $title.' '.mb_substr($t, 0, 600), $m) ? ucfirst(strtolower($m[1])) : '';
        $edu = preg_match_all('/\b(B\.?\s?Tech|B\.?\s?E\b|M\.?\s?Tech|MBA|PGDM|BBA|BCA|MCA|B\.?\s?Com|M\.?\s?Com|B\.?\s?Sc|M\.?\s?Sc|CA\b|Bachelor[\'’]?s?(?: degree)?|Master[\'’]?s?(?: degree)?|Graduate|Post[- ]?graduate|Diploma|PhD)/iu', $t, $m) ? implode(', ', array_slice(array_values(array_unique(array_map('trim', $m[1]))), 0, 5)) : '';
        // Pay only when stated in the posting: ₹ amounts, LPA, CTC or a salary line.
        $pay = (string) ($details['salary'] ?? '') ?: $find('/((?:₹|rs\.?|inr)\s?[\d,.]+\s*(?:lpa|lakhs?|l|k|per month|\/month|pa)?(?:\s*(?:-|to|–)\s*(?:₹|rs\.?|inr)?\s?[\d,.]+\s*(?:lpa|lakhs?|l|k|per month|\/month|pa)?)?|[\d.]+\s*(?:-|to|–)\s*[\d.]+\s*lpa|(?:salary|ctc|compensation)\s*[:\-–]\s*[^\n]{2,60})/iu');
        $kw = AtsEngine::keywords('', $t, $title);
        $skills = array_slice(array_values(array_unique(array_map(fn ($k) => is_array($k) ? (string) ($k['term'] ?? $k['word'] ?? reset($k)) : (string) $k, (array) ($kw['missing'] ?? [])))), 0, 15);
        $preferred = [];
        if (preg_match('/(?:preferred(?: skills)?|nice to have|good to have|bonus|is a plus)\s*[:\-–]?\s*([^\n]{2,240})/iu', $t, $m)) {
            foreach (preg_split('/\s*(?:,|;|\/|\band\b|\bor\b)\s*/iu', $m[1]) as $x) {
                $x = trim($x, " .\t*•-");
                if (mb_strlen($x) >= 2 && mb_strlen($x) <= 40 && count(preg_split('/\s+/', $x)) <= 4) {
                    $preferred[] = $x;
                }
            }
            $preferred = array_slice(array_values(array_unique($preferred)), 0, 8);
        }
        // The job title's own words are not skills.
        $titleWords = array_map('mb_strtolower', preg_split('/\s+/u', $title) ?: []);
        $skills = array_values(array_filter($skills, fn ($k) => ! in_array(mb_strtolower($k), $titleWords, true) && ! in_array(mb_strtolower($k), array_map('mb_strtolower', $preferred), true)));
        $required = array_values(array_diff($skills, $preferred));
        $fields = ['title' => mb_substr($title, 0, 180), 'company' => mb_substr($company, 0, 180), 'location' => mb_substr($location, 0, 120), 'employmentType' => $type, 'seniority' => $seniority, 'experience' => mb_substr($exp, 0, 60),
            'education' => $edu, 'requiredSkills' => $required, 'preferredSkills' => $preferred, 'compensation' => mb_substr($pay, 0, 80), 'sourceUrl' => $url, 'extractedAt' => now()->toIso8601String()];
        $found = count(array_filter([$fields['title'], $fields['company'], $location, $type, $exp, $edu, $required]));
        $fields['confidence'] = $found >= 6 ? 'high' : ($found >= 4 ? 'medium' : 'low');
        $fields['confirmed'] = false;

        return $fields;
    }
}
