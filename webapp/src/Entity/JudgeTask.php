<?php declare(strict_types=1);
namespace App\Entity;

use App\Doctrine\DBAL\Types\JudgeTaskType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Individual judge tasks.
 * TODO: Add indices.
 *
 * @ORM\Entity()
 * @ORM\Table(
 *     name="judgetask",
 *     options={"collate"="utf8mb4_unicode_ci", "charset"="utf8mb4", "comment"="Individual judge tasks."}
 *     )
 */
class JudgeTask
{
    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer", name="judgetaskid", length=4,
     *     options={"comment"="Judgetask ID","unsigned"=true},
     *     nullable=false)
     */
    private $judgetaskid;

    /**
     * @var string
     * @ORM\Column(type="judge_task_type", name="type",
     *     options={"comment"="Type of the judge task.","default"="judging_run"},
     *     nullable=false)
     */
    private $type = JudgeTaskType::JUDGING_RUN;

    /**
     * @var int
     * @ORM\Column(type="integer", name="rank", length=4,
     *     options={"comment"="Priority; negative means higher priority",
     *              "unsigned"=false},
     *     nullable=false)
     */
    private $priority;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="submitid", length=4,
     *     options={"comment"="Submission ID being judged","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $submitid;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="judgingrunid", length=4,
     *     options={"comment"="Corresponding judging run ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $judgingrunid;

    // Note that we rely on the fact here that files with an ID are immutable,
    // so clients are allowed to cache them on disk.
    // TODO: Actually implement immutability :-)

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="compile_script_id", length=4,
     *     options={"comment"="Compile script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $compile_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="run_script_id", length=4,
     *     options={"comment"="Run script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $run_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="compare_script_id", length=4,
     *     options={"comment"="Compare script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $compare_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="input_id", length=4,
     *     options={"comment"="Input ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $input_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="output_id", length=4,
     *     options={"comment"="Expected output ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $output_id;

    /**
     * @var string
     * @ORM\Column(type="text", name="compile_config",
     *     options={"comment"="The compile config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $compile_config;

    /**
     * @var string
     * @ORM\Column(type="text", name="run_config",
     *     options={"comment"="The run config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $run_config;

    /**
     * @var string
     * @ORM\Column(type="text", name="compare_config",
     *     options={"comment"="The compare config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $compare_config;

    public function getJudgetaskid(): int
    {
        return $this->judgetaskid;
    }

    public function setType(string $type): JudgeTask
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setPriority(string $priority): JudgeTask
    {
        $this->priority = $priority;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setSubmitid(int $submitid): JudgeTask
    {
        $this->submitid = $submitid;
        return $this;
    }

    public function getSubmitid(): int
    {
        return $this->submitid;
    }

    public function setJudgingRunId(int $judgingrunid): JudgeTask
    {
        $this->judgingrunid = $judgingrunid;
        return $this;
    }

    public function getJudgingRunId(): int
    {
        return $this->judgingrunid;
    }

    public function setCompileScriptId(int $compile_script_id): JudgeTask
    {
        $this->compile_script_id = $compile_script_id;
        return $this;
    }

    public function getCompileScriptId(): int
    {
        return $this->compile_script_id;
    }

    public function setRunScriptId(int $run_script_id): JudgeTask
    {
        $this->run_script_id = $run_script_id;
        return $this;
    }

    public function getRunScriptId(): int
    {
        return $this->run_script_id;
    }

    public function setCompareScriptId(int $compare_script_id): JudgeTask
    {
        $this->compare_script_id = $compare_script_id;
        return $this;
    }

    public function getCompareScriptId(): int
    {
        return $this->compare_script_id;
    }

    public function setInputId(int $input_id): JudgeTask
    {
        $this->input_id = $input_id;
        return $this;
    }

    public function getInputId(): int
    {
        return $this->input_id;
    }

    public function setOutputId(int $output_id): JudgeTask
    {
        $this->output_id = $output_id;
        return $this;
    }

    public function getOutputId(): int
    {
        return $this->output_id;
    }

    public function setCompileConfig(string $compile_config): JudgeTask
    {
        $this->compile_config = $compile_config;
        return $this;
    }

    public function getCompileConfig(): string
    {
        return $this->compile_config;
    }

    public function setRunConfig(string $run_config): JudgeTask
    {
        $this->run_config = $run_config;
        return $this;
    }

    public function getRunConfig(): string
    {
        return $this->run_config;
    }

    public function setCompareConfig(string $compare_config): JudgeTask
    {
        $this->compare_config = $compare_config;
        return $this;
    }

    public function getCompareConfig(): string
    {
        return $this->compare_config;
    }
}
