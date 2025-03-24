<?php
namespace wapmorgan\PhpCodeFixer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScanCommand extends Command
{
    protected $args;

    const STDOUT = 1;
    const JSON = 2;
    const JUNIT = 3;

    /**
     * @var PhpCodeFixer
     */
    protected $analyzer;

    /** @var string */
    protected $target;

    /** @var string Initial php version */
    protected $after;
	
    /** @var array */
    protected $excludeList = [];

    /** @var array */
    protected $fileExtensions = [];

    /**
     * @var string[]
     */
    protected $skippedChecks = [];

    /**
     * @var Report[]
     */
    protected $reports;

    /**
     * @var boolean
     */
    protected $hasIssue;

    /**
     * @var string
     */
    protected $outputFile = null;

    /**
     * @var int
     */
    protected $outputMode = self::STDOUT;

    /**
     *
     */
    protected function configure()
    {
        $this->setName('scan')
            ->setDescription('Analyzes PHP code and searches issues with deprecated functionality in newer interpreter versions.')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('target', 't', InputOption::VALUE_OPTIONAL,
                        'Sets target PHP interpreter version.', end(PhpCodeFixer::$availableTargets)),
                    new InputOption('after', 'a', InputOption::VALUE_OPTIONAL,
                        'Sets initial PHP interpreter version for checks.', PhpCodeFixer::$availableTargets[0]),
                    new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL,
                        'Sets excluded file or directory names for scanning. If need to pass few names, join it with comma.'),
                    new InputOption('max-size', 's', InputOption::VALUE_OPTIONAL,
                        'Sets max size of php file. If file is larger, it will be skipped.',
                        '1mb'),
                    new InputOption('file-extensions', null, InputOption::VALUE_OPTIONAL,
                        'Sets file extensions to be parsed.',
                        implode(', ', PhpCodeFixer::$defaultFileExtensions)),
                    new InputOption('skip-checks', null, InputOption::VALUE_OPTIONAL,
                        'Skip all checks containing any of the given values. Pass a comma-separated list for multiple values.'),
                    new InputOption('output', null, InputOption::VALUE_OPTIONAL,
                        'The output type required. Options: stdout, json, junit. Defaults to stdout.'),
                    new InputOption('output-file', null, InputOption::VALUE_OPTIONAL,
                        'File path to store results where output is not stdout.'),
                    new InputArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                        'Which files you want to analyze (separate multiple names with a space)?'),
                ])
            );
    }

    /**
     * Runs console application
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if(!empty($input->getOption('output'))){
                switch($input->getOption('output')){
                    case 'json':
                        $this->outputMode = self::JSON;
                        break;
                    case 'junit':
                        $this->outputMode = self::JUNIT;
                        break;
                    case 'stdout':
                        $this->outputMode = self::STDOUT;
                        break;
                    default:
                        throw new ConfigurationException('Output is not valid. Available outputs: stdout, json, junit');
                        break;
                }
            }

            if(!empty($input->getOption('output-file'))){
                if(!in_array($this->outputMode, [self::JSON, self::JUNIT])){
                    throw new ConfigurationException('An output file can only be provided for: json, junit');
                }
                $this->outputFile = trim($input->getOption('output-file'));
            }

            $this->analyzer = $this->configureAnalyzer(new PhpCodeFixer(), $input, $output);
            $this->analyzer->initializeIssuesBank();
            $this->scanFiles($input->getArgument('files'));
            $this->outputAnalyzeResult($output);
            if ($this->hasIssue)
                return 1;
        } catch (ConfigurationException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return 1;
        }

        return 0;
    }

    /**
     *
     * @param PhpCodeFixer $analyzer
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return PhpCodeFixer
     * @throws ConfigurationException
     */
    public function configureAnalyzer(PhpCodeFixer $analyzer, InputInterface $input, OutputInterface $output)
    {
        $this->setTarget($analyzer, $input->getOption('target'), $output);
        $this->setAfter($analyzer, $input->getOption('after'), $output);
        $this->setMaxSize($analyzer, $input->getOption('max-size'), $output);
        $this->setExcludeList($analyzer, $input->getOption('exclude'), $output);
        $this->setFileExtensions($analyzer, $input->getOption('file-extensions'), $output);
        $this->setSkipChecks($analyzer, $input->getOption('skip-checks'), $output);

        return $analyzer;
    }

    /**
     * Sets --target argument
     * @param PhpCodeFixer $analyzer
     * @param $value
     * @param OutputInterface $output
     * @throws ConfigurationException
     */
    public function setTarget(PhpCodeFixer $analyzer, $value, OutputInterface $output)
    {
        if (empty($value)) {
            $analyzer->setTarget($this->target = end(PhpCodeFixer::$availableTargets));
        } else {
            if (!in_array($value, PhpCodeFixer::$availableTargets, true))
                throw new ConfigurationException('Target version is not valid. Available target versions: '.implode(', ', PhpCodeFixer::$availableTargets));
            $analyzer->setTarget($this->target = $value);
        }
    }

    /**
     * Sets --after argument
     * @param PhpCodeFixer $analyzer
     * @param $value
     * @param OutputInterface $output
     * @throws ConfigurationException
     */
    public function setAfter(PhpCodeFixer $analyzer, $value, OutputInterface $output)
    {
        if (empty($value)) {
            $analyzer->setAfter($this->after = PhpCodeFixer::$availableTargets[0]);
        } else {
            if (!in_array($value, PhpCodeFixer::$availableTargets, true))
                throw new ConfigurationException('After version is not valid. Available after versions: '.implode(', ', PhpCodeFixer::$availableTargets));
            $analyzer->setAfter($this->after = $value);
        }
    }

    /**
     * Checks --max-size argument
     * @param PhpCodeFixer $analyzer
     * @param $value
     * @param OutputInterface $output
     */
    public function setMaxSize(PhpCodeFixer $analyzer, $value, OutputInterface $output)
    {
        $size_units = ['b', 'kb', 'mb', 'gb'];
        if (!empty($value)) {
            foreach ($size_units as $unit) {
                if (stripos($value, $unit) > 0) {
                    $max_size_value = (int)stristr($value, $unit, true);
                    $max_size = $max_size_value * pow(1024, array_search($unit, $size_units));
                }
            }

            if (isset($max_size)) {
                if ($this->isVerbose())
                    $output->writeln('<info>Max file size set to: '.$this->formatSize('%.3F Ui', $max_size).'</info>');

                $analyzer->setFileSizeLimit($max_size);
            }
        }
    }

    /**
     * Checks --exclude argument
     */
    protected function setExcludeList(PhpCodeFixer $analyzer, $value, OutputInterface $output)
    {
        if (!empty($value)) {
            $this->excludeList = array_map('strtolower', array_map(function ($dir) { return trim($dir, '/\\ '); }, explode(',', $value)));

            if ($this->isVerbose())
                $output->writeln('<info>Excluding following files / directories: '.implode(', ', $this->excludeList).'</info>');

            $analyzer->setExcludeList($this->excludeList);
        }
    }

    /**
     * Checks --file-extensions argument
     */
    protected function setFileExtensions(PhpCodeFixer $analyzer, $value, OutputInterface $output)
    {
        if (!empty($value)) {
            $exts = array_map('strtolower', array_map('trim', explode(',', $value)));
            if ($exts !== PhpCodeFixer::$defaultFileExtensions) {
                $analyzer->setFileExtensions($exts);

                if ($this->isVerbose())
                    $output->writeln('<info>File extensions set to: '.implode(', ', $exts).'</info>');
            }
        }
    }

    /**
     * @param $skippedChecks
     * @param OutputInterface $output
     */
    public function setSkipChecks(PhpCodeFixer $analyzer, $skippedChecks, OutputInterface $output)
    {
        if (!empty($skippedChecks)) {
            $this->skippedChecks = array_map('strtolower', explode(',', $skippedChecks));

            if ($this->isVerbose())
                $output->writeln('<info>Skipping checks containing any of the following values: ' . implode(', ', $this->skippedChecks).'</info>');

            $analyzer->setExcludedChecks($this->skippedChecks);
        }
    }

    /**
     * Runs analyzer
     * @param array $files
     */
    protected function scanFiles(array $files)
    {
        $this->reports = [];
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->reports[] = $this->analyzer->checkDir(rtrim(realpath($file), DIRECTORY_SEPARATOR));
            } else if (is_file($file)) {
                $report = new Report('File '.basename($file), dirname(realpath($file)));
                $this->reports[] = $this->analyzer->checkFile(realpath($file), $report);
            }
        }
    }

    /**
     * Prints analyzer report
     * @param OutputInterface $output
     * @return int
     */
    protected function outputToStdout(OutputInterface $output)
    {
        $current_php = substr(PHP_VERSION, 0, 3);

        $this->hasIssue = false;
        $total_issues = 0;

        $output->getFormatter()->setStyle('removed_issue', new OutputFormatterStyle('red', null, [/*'bold', 'blink'*/]));
        $output->getFormatter()->setStyle('changed_issue', new OutputFormatterStyle('yellow', null, [/*'bold', 'blink'*/]));
        $output->getFormatter()->setStyle('violation_issue', new OutputFormatterStyle('red', null, ['bold', /*'blink'*/]));

        if (!empty($this->reports)) {
            $replace_suggestions = $notes = [];

            foreach ($this->reports as $report) {
                $output->writeln('');
                $output->writeln('<fg=white>'.$report->getTitle().'</>');

                $info_messages = $report->getInfo();
                if (!empty($info_messages)) {
                    foreach ($info_messages as $message) {
                        switch ($message[0]) {
                            case Report::INFO_MESSAGE:
                                $output->writeln('<fg=yellow>'.$message[1].'</>');
                                break;
                            case Report::INFO_WARNING:
                                $output->writeln('<fg=red>'.$message[1].'</>');
                                break;
                        }
                    }
                }

                $report_issues = $report->getIssues();
                if (!empty($report)) {
					$table = new Table($output);
					$table
						->setHeaders([/*'PHP',*/ 'File (Line:Column)', 'Type', 'Issue']);
                    $versions = array_keys($report_issues);
                    sort($versions);

                    // print issues by version
                    foreach ($versions as $version) {

                        $issues = $report_issues[$version];
                        if (strcmp($current_php, $version) >= 0)
                            $output->writeln('<fg=yellow>- PHP '.$version.' ('.count($issues).') - your version is greater or equal</>');
                        else
                            $output->writeln('<fg=yellow>- PHP '.$version.' ('.count($issues).')</>');
                        $table->setRows($rows = []);

                        // iterate issues
                        foreach ($issues as $issue) {
                            $this->hasIssue = true;
                            $total_issues++;
                            switch ($issue->type) {
                                case 'function':
                                case 'function_usage':
                                    $color = 'yellow';
                                    break;

                                case 'variable':
                                    $color = 'red';
                                    break;

                                case 'ini':
                                    $color = 'green';
                                    break;

                                case 'identifier':
                                    $color = 'blue';
                                    break;

                                case 'constant':
                                    $color = 'gray';
                                    break;

                                default:
                                    $color = 'yellow';
                                    break;
                            }

                            $issue_text = sprintf('%s <%s_issue>%s</%s_issue> is %s.%s',
                                str_replace('_', ' ', ucfirst($issue->type)),
                                $issue->category,
                                $issue->text.($issue->type === ReportIssue::REMOVED_FUNCTION ? '()' : null),
                                $issue->category,
                                $issue->type === ReportIssue::RESERVED_IDENTIFIER ? 'reserved by PHP core' : $issue->category,
                                !empty($issue->replacement)
                                    ? "\n".($issue->category === ReportIssue::CHANGED
                                        ? '<comment>'.$issue->replacement.'</comment>'
                                        : 'Consider replace with <info>'.$issue->replacement
                                            .($issue->type === ReportIssue::REMOVED_FUNCTION ? '()' : null)
                                    .'</info>')
                                    : null
                            );

							$rows[] = [
								'<comment>'.$issue->file.'</comment> ('.$issue->line.':'.$issue->column.')',
								$issue->category,
                                $issue_text,
							];
                        }

                        if (!empty($rows)) {
                        	$table->setRows($rows);
                        	$table->render();
						}
                        $output->writeln('');
                    }
                }
            }

            $output->writeln('');

            if ($total_issues > 0)
                $output->writeln('<bg=red;fg=white>Total issues: '.$total_issues.'</>');
            else
                $output->writeln('<bg=green;fg=white>Analyzer has not detected any issues in your code.</>');
        }

        return $total_issues;
    }

    /**
     * Prints memory consumption
     */
    protected function printMemoryUsage(OutputInterface $output)
    {
        $output->writeln('<info>Peak memory usage: '.$this->formatSize('%.3F U', memory_get_peak_usage(), 'mb').'</info>');
    }

    /**
     * Simplifies path to fit in specific width
     * @param string $path
     * @param integer $maxLength
     * @return string
     */
    public function normalizeAndTruncatePath($path, $maxLength) {
        $truncated = 1;
        $path_parts = explode('/', str_replace('\\', '/', $path));
        $total_parts = count($path_parts);

        while (strlen($path) > $maxLength) {
            if (($truncated + 1) === $total_parts) break;
            $part_to_modify = $total_parts - 1 - $truncated;
            $chars_to_truncate = min(strlen($path_parts[$part_to_modify]) - 1, strlen($path) - $maxLength);
            if ((strlen($path) - $maxLength + 2) < strlen($path_parts[$part_to_modify]))
                $chars_to_truncate += 2;

            $path_parts[$part_to_modify] = substr($path_parts[$part_to_modify], 0, -$chars_to_truncate).'..';
            $path = implode('/', $path_parts);
            $truncated++;
        }

        return $path;
    }

    /**
     * @param string $format Sets format for size.
     * Format should containt string parsable by sprintf() function and contain one %F macro that will be replaced by size. Another macro is U/u. It will be replaced with used unit. U for uppercase, u - lowercase. If 'i' is present at the end of format string, size multiplier will be set to 1024 (and units be KiB, MiB and so on), otherwise multiplier is set to 1000.
     * @example "%.0F Ui" 617 KiB
     * @example "%.3F Ui" 617.070 KiB
     * @example "%10.3F Ui"     616.85 KiB
     * @example "%.3F U" 632.096 KB
     *
     * @param integer $bytes Size in bytes
     * @param string $unit Sets default unit. Can have these values: B, KB, MG, GB, TB, PB, EB, ZB and YB
     * @return string
     */
    public function formatSize($format, $bytes, $unit = '') {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $bytes = max($bytes, 0);
        $unit = strtoupper($unit);

        if (substr($format, -1) === 'i') {
            $multiplier = 1024;
            $format = substr($format, 0, -1);
        }
        else
            $multiplier = 1000;

        if ($unit === '' || !in_array($unit, $units)) {
            $pow = floor(($bytes ? log($bytes) : 0) / log($multiplier));
            $pow = min($pow, count($units) - 1);

            $bytes /= pow($multiplier, $pow);
            $unit = $units[$pow];
        } else {
            $pow = array_search($unit, $units);
            $bytes /= pow($multiplier, $pow);
        }

        if ($multiplier == 1024)
            $unit = (strlen($unit) == 2) ? substr($unit, 0, 1).'iB' : $unit;
        if (strpos($format, 'u') !== false)
            $format = str_replace('u', strtolower($unit), $format);
        else
            $format = str_replace('U', $unit, $format);

        return sprintf($format, $bytes);
    }

    /**
     * @param $jsonFile
     * @return int
     */
    protected function outputToJson($jsonFile)
    {
        $data = [
            'info_messages' => [],
            'problems' => [],
            'replace_suggestions' => [],
            'notes' => [],
        ];

        $total_issues = 0;
        if (!empty($this->reports)) {

            foreach ($this->reports as $report) {
                $info_messages = $report->getInfo();
                if (!empty($info_messages)) {
                    foreach ($info_messages as $message) {
                        $data['info_messages'][] = [
                            'type' => $message[0] === Report::INFO_MESSAGE ? 'info' : 'warning',
                            'message' => $message[1]
                        ];
                    }
                }

                $report_issues = $report->getIssues();
                if (!empty($report)) {
                    $versions = array_keys($report_issues);
                    sort($versions);

                    // print issues by version
                    foreach ($versions as $version) {
                        // iterate issues
                        foreach ($report_issues[$version] as $issue) {
                            $this->hasIssue = true;
                            $total_issues++;

                            $data['problems'][] = [
                                'version' => $version,
                                'file' => $issue->file,
                                'path' => $report->getRemovablePath().$issue->file,
                                'line' => $issue->line,
                                'column' => $issue->column,
                                'category' => $issue->category,
                                'type' => $issue->type,
                                'checker' => $issue->text,
                            ];

                            if (!empty($issue->replacement)) {
                                if ($issue->category === ReportIssue::CHANGED) {
                                    $data['notes'][] = [
                                        'type' => $issue->type,
                                        'problem' => $issue->text,
                                        'note' => $issue->replacement,
                                    ];
                                } else {
                                    $data['replace_suggestions'][] = [
                                        'type' => $issue->type,
                                        'problem' => $issue->text.($issue->type === 'function' ? '()' : null),
                                        'replacement' => $issue->replacement.($issue->type === 'function' ? '()' : null),
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        $json = json_encode(array_filter($data, function ($value) {
            return count($value) > 0;
        }), JSON_PRETTY_PRINT);

        if (in_array($jsonFile, ['', null]))
            fwrite(STDOUT, $json);
        else
            file_put_contents($jsonFile, $json);

        return $total_issues;
    }

    /**
     * @param $junitFile
     * @return int
     */
    protected function outputToJunit($junitFile)
    {

        $total_issues = 0;
        $total_tests = 0;
        $data = [];
        $filesWithFailures = [];
        if (!empty($this->reports)) {
            foreach ($this->reports as $report) {
                $report_issues = $report->getIssues();
                if (!empty($report)) {
                    $versions = array_keys($report_issues);
                    sort($versions);

                    // print issues by version
                    foreach ($versions as $version) {
                        // iterate issues
                        foreach ($report_issues[$version] as $issue) {
                            $key = $issue->file.'_'.$version;
                            if(!array_key_exists($key, $data)){
                                $data[$key] = [
                                    'name' => $issue->path . ' (PHP ' . $version . ')',
                                    'failures' => [],
                                ];
                            }
                            $data[$key]['failures'][] = $issue;
                            $total_issues++;
                            $total_tests++;
                            $filesWithFailures[] = $issue->path;
                        }
                    }
                }
            }
        }

        foreach(array_diff($this->analyzer->scannedFiles, $filesWithFailures) as $path){
            $data[] = [
                'name' => $path,
                'failures' => [],
            ];
        }

        // add files that passed as a test
        $total_tests += count(array_diff($this->analyzer->scannedFiles, $filesWithFailures));

        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        echo '<testsuites name="PhpDeprecationDetector '.PhpCodeFixer::VERSION.'" errors="0" tests="'.$total_tests.'" failures="'.$total_issues.'">'.PHP_EOL;

        foreach($data as $test){
            $out = new \XMLWriter;
            $out->openMemory();
            $out->setIndent(true);

            $out->startElement('testsuite');
            $out->writeAttribute('name', $test['name']);
            $out->writeAttribute('errors', 0);

            if (count($test['failures']) === 0) {
                $out->writeAttribute('tests', 1);
                $out->writeAttribute('failures', 0);

                $out->startElement('testcase');
                $out->writeAttribute('name', $test['name']);
                $out->endElement();
            } else {

                $out->writeAttribute('tests', count($test['failures']));
                $out->writeAttribute('failures', count($test['failures']));
                
                if(count($test['failures']) > 0){
                    // sort by line+column
                    usort($test['failures'], function($a, $b){
                        $diff = $a->line - $b->line;
                        return ($diff !== 0) ? $diff : $a->column - $b->column;
                    });

                    foreach($test['failures'] as $failure){
                        $out->startElement('testcase');
                        $out->writeAttribute('name', $failure->text.' at '.$failure->path." ($failure->line:$failure->column)");
                        
                        $out->startElement('failure');
                        
                        if (!empty($failure->replacement)) {
                            if ($failure->category === ReportIssue::CHANGED) {
                                $out->writeAttribute('type', $failure->type);
                                $out->writeAttribute('message', 'Problem: ' . $failure->text . '; Note: ' . $failure->replacement);
                            } else {
                                $out->writeAttribute('type', $failure->type);
                                $out->writeAttribute('message', 'Problem: ' . $failure->text.($failure->type === 'function' ? '()' : '') . '; Replacement: ' . $failure->replacement.($failure->type === 'function' ? '()' : ''));
                            }
                        } else {
                            $out->writeAttribute('type', $failure->type);
                            $out->writeAttribute('message', $failure->category);
                        }

                        $out->endElement();

                        $out->endElement();
                    }
                }
            }
            $out->endElement();
            echo $out->flush();
        }

        echo '</testsuites>'.PHP_EOL;
        $junit = ob_get_clean();

        if (in_array($junitFile, ['', null]))
            fwrite(STDOUT, $junit);
        else
            file_put_contents($junitFile, $junit);

        return $total_issues;
    }

    /**
     * @param OutputInterface $output
     */
    protected function outputAnalyzeResult(OutputInterface $output)
    {
        switch ($this->outputMode) {
            case self::STDOUT:
                $this->outputToStdout($output);
                $this->printMemoryUsage($output);
                break;

            case self::JSON:
                $total_issues = $this->outputToJson($this->outputFile);
                if ($this->isVerbose()) {
                    if ($total_issues > 0)
                        $output->writeln('<bg=red;fg=white>Total problems: ' . $total_issues . '</>');
                    else
                        $output->writeln('<bg=green;fg=white>Analyzer has not detected any problems in your code.</>');
                    $this->printMemoryUsage($output);
                }
                break;

            case self::JUNIT:
                $total_issues = $this->outputToJunit($this->outputFile);
                if ($this->isVerbose()) {
                    if ($total_issues > 0)
                        $output->writeln('<bg=red;fg=white>Total problems: ' . $total_issues . '</>');
                    else
                        $output->writeln('<bg=green;fg=white>Analyzer has not detected any problems in your code.</>');
                    $this->printMemoryUsage($output);
                }
                break;
        }

    }

    /**
     * Returns flag that extra information can be printed on stdout
     * @return bool
     */
    protected function isVerbose()
    {
        return $this->outputMode == self::STDOUT;
    }
}
