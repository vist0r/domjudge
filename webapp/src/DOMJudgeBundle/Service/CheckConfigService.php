<?php declare(strict_types=1);

namespace DOMJudgeBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use DOMJudgeBundle\Entity\Executable;
use DOMJudgeBundle\Entity\Judgehost;
use DOMJudgeBundle\Entity\Language;
use DOMJudgeBundle\Entity\Problem;
use DOMJudgeBundle\Entity\Submission;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Entity\TeamAffiliation;
use DOMJudgeBundle\Entity\User;
use DOMJudgeBundle\Utils\Utils;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class CheckConfigService
 * @package DOMJudgeBundle\Service
 */
class CheckConfigService
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var bool
     */
    protected $debug;

    /**
     * @var string
     */
    protected $project_dir;

    public function __construct(bool $debug,
        string $project_dir,
        EntityManagerInterface $entityManager,
        DOMJudgeService $DOMJudgeService,
        ValidatorInterface $validator
    ) {
        $this->debug             = $debug;
        $this->project_dir       = $project_dir;
        $this->entityManager     = $entityManager;
        $this->DOMJudgeService   = $DOMJudgeService;
        $this->validator         = $validator;
    }

    public function runAll()
    {
        $results = [];

        $system = [
            'php_version' => $this->checkPhpVersion(),
            'php_extensions' => $this->checkPhpExtensions(),
            'php_settings' => $this->checkPhpSettings(),
            'mysql_version' => $this->checkMysqlVersion(),
            'mysql_settings' => $this->checkMysqlSettings(),
        ];

        $results['System'] = $system;

        $config = [
            'adminpass' => $this->checkAdminPass(),
            'comparerun' => $this->checkDefaultCompareRunExist(),
            'filesizememlimit' => $this->checkScriptFilesizevsMemoryLimit(),
            'debugdisabled' => $this->checkDebugDisabled(),
            'tmpdirwritable' => $this->checkTmpdirWritable(),
            'submitdirwritable' => $this->checkSubmitdirWritable(),
        ];

        $results['Configuration'] = $config;

        $contests = [
            'activecontests' => $this->checkContestActive(),
            'validcontests' => $this->checkContestsValidate(),
        ];

        $results['Contests'] = $contests;

        $pl = [
            'problems' => $this->checkProblemsValidate(),
            'languges' => $this->checkLanguagesValidate(),
            'judgability' => $this->checkProblemLanguageJudgability(),
        ];

        $results['Problems and languages'] = $pl;

        $teams = [
            'affiliations' => $this->checkAffiliations(),
            'teamdupenames' => $this->checkTeamDuplicateNames(),
        ];

        $results['Teams'] = $teams;

        $submissions = [
            'submission' => $this->checkSubmissionsValidate(),
        ];

        $results['Submissions'] = $submissions;

        return $results;
    }

    public function checkPhpVersion()
    {
        $my = PHP_VERSION;
        $req = '7.0';
        $result = version_compare($my, $req, '>=');
        return ['caption' => 'PHP version',
                'result' => ($result ? 'O' : 'E'),
                'desc' => sprintf('You have PHP version %s. The minimum required is %s', $my, $req)];
    }

    public function checkPhpExtensions()
    {
        $required = ['json', 'mbstring', 'mysqli', 'zip', 'gd', 'intl'];
        $optional = ['curl' => 'exporting to/importing from Baylor website'];

        $state = 'O'; $remark = '';
        foreach($optional as $ext => $why) {
            if ( !extension_loaded($ext) ) {
                $state = 'W';
		$remark .= sprintf("Optional PHP extension '%s' not loaded; needed for %s\n",
                    $ext, $why);
            }
        }
        foreach($required as $ext) {
            if ( !extension_loaded($ext) ) {
                $state = 'E';
                $remark .= sprintf("Required PHP extension '%s' not loaded.\n", $ext);
            }
        }
        $remark = ($remark ?: 'All required and recommended extensions present.');

        return ['caption' => 'PHP extensions',
                'result' => $state,
                'desc' => $remark];
    }

    public function checkPhpSettings()
    {
        $sourcefiles_limit = $this->DOMJudgeService->dbconfig_get('sourcefiles_limit', 100);
        $max_files = ini_get('max_file_uploads');

        $result = 'O';
        if ($max_files < max(100, $sourcefiles_limit)) {
            $result = 'W';
        }
        $desc = sprintf("PHP 'max_file_uploads' set to %s. This should be set higher than the maximum number of test cases per problem and the DOMjudge configuration setting 'sourcefiles_limit' (now set to %s)", $max_files, $sourcefiles_limit);

        $sizes = [];
        $postmaxvars = ['post_max_size', 'memory_limit', 'upload_max_filesize'];
        foreach ($postmaxvars as $var) {
            /* skip 0 or empty values, and -1 which means 'unlimited' */
            if ($size = Utils::phpini_to_bytes(ini_get($var))) {
                if ($size != '-1') {
                    $sizes[$var] = $size;
                }
            }
        }
        if (min($sizes) < 52428800) {
            $result = 'W';
        }

        $desc .= "\n\n" . sprintf('PHP POST/upload filesize is limited to %s.', Utils::printsize(min($sizes)));
        $desc .= "\n\nThis limit needs to be larger than the testcases you want to upload and than the amount of program output you expect the judgedaemons to post back to DOMjudge. We recommend at least 50 MB.\n\nNote that you need to ensure that all of the following php.ini parameters are at minimum the desired size:\n";
        foreach ($postmaxvars as $var) {
            $desc .= sprintf("%s (now set to %s)\n", $var, 
                    (isset($sizes[$var]) ? Utils::printsize($sizes[$var]) : "unlimited"));
        }

        return ['caption' => 'PHP settings',
                'result' => $result,
                'desc' => $desc];
    }

    public function checkMysqlVersion()
    {
        $r = $this->entityManager->getConnection()->fetchAll('SHOW VARIABLES WHERE variable_name = "version"'); 
        $my = $r[0]['Value'];
        $req = '5.5.3';
        $result = version_compare($my, $req, '>=');
        return ['caption' => 'MySQL version',
                'result' => ($result ? 'O' : 'E'),
                'desc' => sprintf('You have MySQL version %s. The minimum required is %s', $my, $req)];
    }

    public function checkMysqlSettings()
    {
        $r = $this->entityManager->getConnection()->fetchAll('SHOW variables WHERE Variable_name IN
                        ("innodb_log_file_size", "max_connections", "max_allowed_packet", "tx_isolation")'); 
        $vars = [];
        foreach($r as $row) {
            $vars[$row['Variable_name']] = $row['Value'];
        }
        $max_inout_r = $this->entityManager->getConnection()->fetchAll('SELECT GREATEST(MAX(LENGTH(input)),MAX(LENGTH(output))) as max FROM testcase');
        $max_inout = (int)reset($max_inout_r)['max'];

        $result = 'O';
        $desc = '';
        if($vars['max_connections'] < 300) {
            $result = 'W';
            $desc .= sprintf("MySQL's max_connections is set to %s. In our experience you need at least 300, but better 1000 connections to prevent connection refusal during the contest.\n", $vars['max_connections']);
        }

        if($vars['innodb_log_file_size'] < 128*1024*1024) {
            $result = 'W';
            $desc .= sprintf("MySQL's innodb_log_file_size is set to %s. You may want to raise this to 10x the maximum test case size (now %s).\n", Utils::printsize((int)$vars['innodb_log_file_size']), Utils::printsize($max_inout));
        }

        $tx = ['REPEATABLE-READ', 'SERIALIZABLE'];
        if ( ! in_array($vars['tx_isolation'], $tx) ) {
            $result = 'W';
            $desc .= sprintf("MySQL's transaction isolation level is set to %s. You should set this to %s to prevent data inconsistencies.\n", $vars['tx_isolation'], implode(' or ', $tx));
        }

        $recommended_max_allowed_packet = 16*1024*1024;
        if ($vars['max_allowed_packet'] < 2*$max_inout) {
            $result = 'E';
            $desc .= sprintf("MySQL's max_allowed_packet is set to %s. You may want to raise this to about twice the maximum test case size (currently %s).\n", Utils::printsize((int)$vars['max_allowed_packet']), Utils::printsize($max_inout));
        } elseif ($vars['max_allowed_packet'] < $recommended_max_allowed_packet) {
            $result = 'W';
            $desc .= sprintf("MySQL's max_allowed_packet is set to %s. You may want to raise this to about twice the maximum test case size (currently %s).\n", Utils::printsize((int)$vars['max_allowed_packet']), Utils::printsize($max_inout));
        }

        return ['caption' => 'MySQL settings',
                'result' => $result,
                'desc' => $desc];
    }

    public function checkAdminPass()
    {
        $res = 'O';
        $desc = 'Password for "admin" has been changed from the default.';

        /** @var User $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if ( $user && password_verify('admin', $user->getPassword())) {
            $res = 'E';
            $desc = 'The "admin" user still has the default password. You should change it immediately.';
        }

        return ['caption' => 'Non-default admin password',
                'result' => $res,
                'desc' => $desc];
    }

    public function checkDefaultCompareRunExist()
    {
        $res = 'O';
        $desc = '';

        $scripts = ['compare', 'run'];
        foreach($scripts as $type) {
            $scriptid = $this->DOMJudgeService->dbconfig_get('default_' . $type);
            if ( ! $this->entityManager->getRepository(Executable::class)->find($scriptid) ) {
                $res = 'E';
                $desc .= sprintf("The default %s script '%s' does not exist.\n", $type, $scriptid);
            } else {
                $desc .= sprintf("The default %s script '%s' exists.\n", $type, $scriptid);
            }
        }

        return ['caption' => 'Default compare and run scripts exist',
                'result' => $res,
                'desc' => $desc];
    }

    public function checkScriptFilesizevsMemoryLimit()
    {
        if ( $this->DOMJudgeService->dbconfig_get('script_filesize_limit') < 
             $this->DOMJudgeService->dbconfig_get('memory_limit') ) {
             $result = 'W';
        } else {
             $result = 'O';
        }
        return ['caption' => 'Compile file size vs. memory limit',
                'result' => $result,
                'desc' => 'If the script filesize limit is lower than the memory limit, then ' .
                    'compilation of sources that statically allocate memory may fail.'];
    }

    public function checkDebugDisabled()
    {
        if ( $this->debug ) {
            return ['caption' => 'Debugging',
                'result' => 'W',
                'desc' => "Debugging enabled.\nShould not be enabled on live systems."];
        }
        return ['caption' => 'Debugging',
                'result' => 'O',
                'desc' => 'Debugging disabled.'];
    }

    public function checkTmpdirWritable()
    {
        $tmpdir = $this->DOMJudgeService->getDomjudgeTmpDir();
        if ( is_writable($tmpdir) ) {
            return ['caption' => 'TMPDIR writable',
                    'result' => 'O',
                    'desc' => sprintf('TMPDIR (%s) can be used to store temporary ' .
                         'files for submission diffs and edits.',
                         $tmpdir)];
        }
        return ['caption' => 'TMPDIR writable',
                'result' => 'W',
                'desc' => sprintf('TMPDIR (%s) is not writable by the webserver; ' .
                 'Showing diffs and editing of submissions may not work.',
                 $tmpdir)];
    }

    public function checkSubmitdirWritable()
    {
        $submitdir = $this->DOMJudgeService->getDomjudgeSubmitdir();
        if ( is_writable($submitdir) ) {
            return ['caption' => 'Submitdir writable',
                    'result' => 'O',
                    'desc' => sprintf('Submitdir (%s) can be used to save backup ' .
                         'copies of submissioms.',
                         $submitdir)];
        }
        return ['caption' => 'Submitdir writable',
                'result' => 'W',
                'desc' => sprint('The webserver has no write access to Submitdir (%s), ' .
                'and thus will not be able to make backup copies of submissions.', $submitdir)];
    }


    public function checkContestActive()
    {
        $contests = $this->DOMJudgeService->getCurrentContests();
        if ( empty($contests) ) {
            return ['caption' => 'Active contests',
                    'result' => 'E',
                    'desc' => 'No currently active contests found. System will not function.'];
        }
        return ['caption' => 'Active contests',
                'result' => 'O',
                'desc' => 'Currently active contests: ' .  
                    implode(', ', array_map(function ($contest) {
                        return 'c'.$contest->getCid() . ' (' . $contest->getShortname() . ')';
                    }, $contests))];
    }


    public function checkContestsValidate()
    {
        // Fetch all active and future contests
        $contests = $this->DOMJudgeService->getCurrentContests(false, null, true);

        $contesterrors = $cperrors = [];
        $result = 'O';
        foreach($contests as $contest) {
            $cid = $contest->getCid();
            $errors = $this->validator->validate($contest);
            if ( count($errors) ) {
                $result = 'E';
            }
            $contesterrors[$cid] = $errors;

            $cperrors[$cid] = '';
            foreach($contest->getProblems() as $cp) {
                if(empty($cp->getColor())) {
                    $result = ($result == 'E' ? 'E' : 'W');
                    $cperrors[$cid] .= "No color for problem " . $cp->getShortname() . " in contest c" . $cid . "\n";
                }
            }
        }

        $desc = '';
        foreach($contesterrors as $cid => $errors) {
            $desc .= "Contest: c$cid: " .
                    (count($errors) == 0 ? 'no errors' : (string)$errors) ."\n" .$cperrors[$cid];
        }

        return ['caption' => 'Contests validation',
            'result' => $result,
            'desc' => "Validated all active and future contests:\n\n" .
                    ($desc ?: 'No problems found.')];
    }


    public function checkProblemsValidate()
    {
        $problems = $this->entityManager->getRepository(Problem::class)->findAll();
        $script_filesize_limit = $this->DOMJudgeService->dbconfig_get('script_filesize_limit');

        $problemerrors = $scripterrors = [];
        $result = 'O';
        foreach($problems as $problem) {
            $probid = $problem->getProbid();
            $errors = $this->validator->validate($problem);
            if ( count($errors) ) {
                $result = 'E';
            }
            $problemerrors[$probid] = $errors;

            $moreproblemerrors[$probid] = '';
            if ( $special_compare = $problem->getSpecialCompare() ) {
                $exec = $this->entityManager->getRepository(Executable::class)->findOneBy(['execid' => $special_compare]);
                if ( !$exec ) {
                    $result = 'E';
                    $moreproblemerrors[$probid] .= sprintf("Special compare script %s not found for p%s\n", $special_compare, $probid);
                } elseif ( $exec->getType() !== "compare" ) { 
                    $result = 'E';
                    $moreproblemerrors[$probid] .= sprintf("Special compare script %s exists but is of wrong type (%s instead of compare) for p%s\n", $special_compare, $exec->getType(), $probid);
                }
            }
            if ( $special_run = $problem->getSpecialRun() ) {
                $exec = $this->entityManager->getRepository(Executable::class)->findOneBy(['execid' => $special_run]);
                if ( !$exec ) {
                    $result = 'E';
                    $moreproblemerrors[$probid] .= sprintf("Special run script %s not found for p%s\n", $special_run, $probid);
                } elseif ( $exec->getType() !== "run" ) { 
                    $result = 'E';
                    $moreproblemerrors[$probid] .= sprintf("Special run script %s exists but is of wrong type (%s instead of run) for p%s\n", $special_run, $exec->getType(), $probid);
                }
            }

            $memlimit = $problem->getMemlimit();
            if ( $memlimit !== null && $memlimit > $script_filesize_limit) {
                $result = 'E';
                $moreproblemerrors[$probid] .= sprintf("problem-specific memory limit %s is larger than global script filesize limit (%s).\n", $memlimit, $script_filesize_limit);
            }

            $tcs = $problem->getTestcases();
            if ( count($tcs) === 0 ) {
                $result = 'E';
                $moreproblemerrors[$probid] .= sprintf("No testcases for p%s\n", $probid);
            } else {
                // TODO: check for testcase size vs output_limit in an efficient way.
            }
        }

        $desc = '';
        foreach($problemerrors as $probid => $errors) {
            $desc .= "Problem p$probid: ";
            if ( count($errors) > 0 || !empty($moreproblemerrors[$probid]) ) {
                $desc .= (string)$errors . " " .
                    $moreproblemerrors[$probid] . "\n";
            } else {
                $desc .= "OK\n";
            }
        }

        return ['caption' => 'Problems validation',
            'result' => $result,
            'desc' => "Validated all problems:\n\n" .
                    ($desc ?: 'No problems with problems found.')];
    }

    public function checkLanguagesValidate()
    {
        $languages = $this->entityManager->getRepository(Language::class)->findAll();
        $script_filesize_limit = $this->DOMJudgeService->dbconfig_get('script_filesize_limit');

        $languageerrors = $scripterrors = [];
        $result = 'O';
        foreach($languages as $language) {
            $langid = $language->getLangid();
            $errors = $this->validator->validate($language);
            if ( count($errors) ) {
                $result = 'E';
            }
            $languageerrors[$langid] = $errors;

            $morelanguageerrors[$langid] = '';
            if ( $compile = $language->getCompileScript() ) {
               $exec = $this->entityManager->getRepository(Executable::class)->findOneBy(['execid' => $compile]);
               if ( !$exec ) {
                   $result = 'E';
                   $morelanguageerrors[$langid] .= sprintf("Compile script %s not found for %s\n", $compile, $langid);
               } elseif ( $exec->getType() !== "compile" ) { 
                   $result = 'E';
                   $morelanguageerrors[$langid] .= sprintf("Compile script %s exists but is of wrong type (%s instead of compile) for %s\n", $compile, $exec->getType(), $langid);
               }
            }
        }

        $desc = '';
        foreach($languageerrors as $langid => $errors) {
            $desc .= "Language $langid: ";
            if ( count($errors) > 0 || !empty($morelanguageerrors[$langid]) ) {
                $desc .= (string)$errors . " " .
                $morelanguageerrors[$langid] . "\n";
            } else {
                $desc .= "OK\n";
            }
        }

        return ['caption' => 'Languages validation',
            'result' => $result,
            'desc' => "Validated all languages:\n\n" .
                    ($desc ?: 'No languages with problems found.')];
    }

    public function checkProblemLanguageJudgability()
    {
        $judgehosts = $this->entityManager->getRepository(Judgehost::class)->findBy(['active' => 1]);

        foreach($judgehosts as $judgehost) {
            if ( $judgehost->getRestrictionid() === null ) {
                return ['caption' => 'Problem, language and contest judgability',
                    'result' => 'O',
                    'desc' => sprintf("At least one judgehost (%s) is active and unrestricted.", $judgehost->getHostname())];
            }
        }

        $languages = $this->entityManager->getRepository(Language::class)->findAll();
        $contests = $this->DOMJudgeService->getCurrentContests(false, null, true);

        $desc = '';
        $result = 'O';
        foreach($contests as $contest) {
            foreach($contest->getProblems() as $cp ) {
                foreach($languages as $lang) {
                    if ( !$lang->getAllowSubmit() ) {
                        continue;
                    }
                    $found1 = false;
                    foreach($judgehosts as $judgehost) {
                        $rest = $judgehost->getRestriction();
                        $rest_c = $rest->getContests();
                        $rest_p = $rest->getProblems();
                        $rest_l = $rest->getLanguages();
                        if ( ( empty($rest_c) || in_array($contest->getCid(), $rest_c) ) &&
                             ( empty($rest_p) || in_array($cp->getProbid(), $rest_p) ) &&
                             ( empty($rest_l) || in_array($lang->getLangid(), $rest_l) ) ) {
                            $found1 = true;
                            continue;
                        }
                    }
                    if ( ! $found1 ) {
                        $result = 'E';
                        $desc .= sprintf("No active judgehost that allows combination c%s-p%s-%s\n",
                            $contest->getCid(), $cp->getProbid(), $lang->getLangid());
                    }
                }
            }
        }
        $desc = $desc ?: 'Found at least one judgehost for each combination of current/future contest, associated problem, enabled language';

        return ['caption' => 'Problem, language and contest judgability',
            'result' => $result,
            'desc' => $desc];
    }

    public function checkAffiliations()
    {
        $show_affiliations = $this->DOMJudgeService->dbconfig_get('show_affiliations');
        $show_logos = $this->DOMJudgeService->dbconfig_get('show_affiliation_logos');
        $show_flags = $this->DOMJudgeService->dbconfig_get('show_flags');

        if ( !$show_affiliations || (!$show_logos && !$show_flags) ) {
            return ['caption' => 'Team affiliations',
                'result' => 'O',
                'desc' => 'Affiliations display disabled, skipping checks'];
        }

        $affils = $this->entityManager->getRepository(TeamAffiliation::class)->findAll();

        $result = 'O';
        $desc = '';
        $webDir = sprintf('%s/webapp/web/', $this->project_dir);
        foreach($affils as $affiliation) {
            // don't care about unused affiliations
            if ( count($affiliation->getTeams()) === 0 ) {
                continue;
            }
            if ( $show_flags ) {
                if ( $countryCode = $affiliation->getCountry() ) {
                    $flagpath = $webDir . sprintf('images/countries/%s.png', $countryCode);
                    if ( ! file_exists($flagpath) ) {
                        $result = 'W';
                        $desc .= sprintf("Flag for %s does not exist (looking for %s)\n", $countryCode, $flagpath);
                    } elseif ( ! is_readable($flagpath) ) {
                         $result = 'W';
                          $desc .= sprintf("Flag for %s not readable (looking for %s)\n", $countryCode, $flagpath);
                    }
                }
            }
            if ( $show_logos ) {
                if ($aid = $affiliation->getAffilid()) {
                    $logopaths = [$webDir . sprintf('images/affiliations/%s.png', $aid)];
                    if ($externalAffilid = $affiliation->getExternalid()) {
                        $logopaths[] = $webDir . sprintf('images/affiliations/%s.png', $externalAffilid);
                    }
                    $exists   = false;
                    $readable = false;
                    foreach ($logopaths as $logopath) {
                        if (file_exists($logopath)) {
                            $exists = true;
                            if (is_readable($logopath)) {
                                $readable = true;
                            }
                        }
                    }
                    if (!$exists) {
                        $result = 'W';
                        $desc   .= sprintf("Logo for %s does not exist (looking for %s)\n",
                                           $affiliation->getShortname(), implode(', ', $logopaths));
                    } elseif (!$readable) {
                        $result = 'W';
                        $desc   .= sprintf("Logo for %s not readable (looking for %s)\n", $affiliation->getShortname(),
                                           implode(', ', $logopaths));
                    }
                }
            }
        }
        $desc = $desc ?: 'Everything OK';

        return ['caption' => 'Team affiliations',
            'result' => $result,
            'desc' => $desc];
    }

    public function checkTeamDuplicateNames()
    {
        $teams = $this->entityManager->getRepository(Team::class)->findAll();

        $result = 'O';
        $desc = '';
        $seen = [];
        foreach($teams as $team) {
            $seen[$team->getName()][] = $team->getTeamid();
        }
        foreach($seen as $teamname => $teams) {
            if ( count($teams) > 1 ) {
                $result = 'W';
                $desc .= sprintf("Team name '%s' in use by multiple teams: %s",
                         $teamname, implode(',', $teams));
            }
        }
        $desc = $desc ?: 'Every team name is unique';

        return ['caption' => 'Team name uniqueness',
            'result' => $result,
            'desc' => $desc];
    }

    public function checkSubmissionsValidate()
    {
        $submissions = $this->entityManager->getRepository(Submission::class)->findAll();

        $submissionerrors = [];
        $result = 'O';
        foreach($submissions as $submission) {
            $submitid = $submission->getSubmitid();
            $errors = $this->validator->validate($submission);
            if ( count($errors) ) {
                $result = 'E';
            }
            $submissionerrors[$submitid] = $errors;

            $moresubmissionerrors[$submitid] = '';
            if ( count($submission->getFiles()) === 0 ) {
                $result = 'E';
                $moresubmissionerrors[$submitid] .= sprintf("has no associated files\n", $submitid);
            }
            if ( $submission->getJudgehost() !== null && count($submission->getJudgings()) === 0 ) {
                $result = 'E';
                $moresubmissionerrors[$submitid] .= sprintf("has a judgehost but no judgings\n", $submitid);
            }
            $valids = 0;
            foreach($submission->getJudgings() as $judging) {
                $valids += (int)$judging->getValid();

                if ($judging->getValid() && $judging->getEndtime() === null &&
                    Utils::difftime($judging->getStarttime(), Utils::now()) > 300) {
                    $result = ($result == 'E') ? 'E' : 'W';
                    $moresubmissionerrors[$submitid] .= sprintf("has been running for more than 5 minutes without a result\n", $submitid);
                }
            }
            if ( $valids > 1 ) {
                $result = 'E';
                $moresubmissionerrors[$submitid] .= sprintf("has more than 1 valid judging\n", $submitid);
            }
        }

        $desc = '';
        foreach($submissionerrors as $sid => $errors) {
            if ( count($errors) > 0 || !empty($moresubmissionerrors[$sid]) ) {
                $desc .= "Submission s$sid: ";
                $desc .= (string)$errors . " " .
                    $moresubmissionerrors[$sid] . "\n";
            }
        }

        return ['caption' => 'Submissions validation',
            'result' => $result,
            'desc' => "Validated all submissions:\n\n" .
                    ($desc ?: 'No submissions with problems found.')];
    }
}