<?php declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\JudgeTask;
use App\Entity\Submission;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::preRemove, entity: Submission::class)]
class SubmissionDeleteListener {
    public function __construct(protected readonly EntityManagerInterface $em)
    {
    }
    public function __invoke(Submission $submission, PreRemoveEventArgs $event): void
    {
        $judgeTasks = $this->em->getRepository(JudgeTask::class)->findBy(
            ['submitid' => $submission->getSubmitid()]
        );
        dump($judgeTasks);

        foreach ($judgeTasks as $judgeTask) {
            $this->em->remove($judgeTask);
        }
        $this->em->flush();
    }
}
