<?php
namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Files;
use App\Entity\Idea;
use App\Entity\Vote;
use App\Form\CommentsType;
use App\Repository\AccountRepository;
use App\Repository\AnswerRepository;
use App\Repository\CommentRepository;
use App\Repository\FilesRepository;
use App\Repository\IdeaRepository;
use App\Repository\VoteRepository;
use App\Service\Functions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use DateTimeInterface;

class Ideas extends AbstractController
{
    private AccountRepository $accountRepository;
    private IdeaRepository $ideaRepository;
    private VoteRepository $voteRepository;
    private FilesRepository $filesRepository;
    private CommentRepository $commentRepository;
    private AnswerRepository $answerRepository;

    public function __construct(EntityManagerInterface $entityManager, FilesRepository $filesRepository, VoteRepository $voteRepository, AnswerRepository $answerRepository, AccountRepository $accountRepository, IdeaRepository $ideaRepository, CommentRepository $commentRepository)
    {
        $this->entityManager = $entityManager;
        $this->ideaRepository = $ideaRepository;
        $this->filesRepository = $filesRepository;
        $this->voteRepository = $voteRepository;
        $this->accountRepository = $accountRepository;
        $this->commentRepository = $commentRepository;
        $this->answerRepository = $answerRepository;
    }

    #[Route('/idea')]
    public function ideas(Request $request): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);

        $author = $this->accountRepository->find($_SESSION['account_id']);

        $idea = new Idea();
        $idea->setTitle($request->request->get('title_idea'));
        $idea->setDetails($request->request->get('details_idea'));
        $idea->setChoiceMesures($request->request->get('mesures'));
        $idea->setDetailsMesures($request->request->get('details_mesures'));
        $idea->setChoiceFunding($request->request->get('funding'));
        $idea->setDetailsFunding($request->request->get('funding_details'));
        $idea->setAuthor($author);
        $idea->setTeam($request->request->get('team'));
        $idea->setState('waiting_approval');
        $idea->setArchived(false);
        $idea->setCreationDateTime(new \DateTime());
        $author->setAuthor(true);
        $this->entityManager->persist($idea);
        
        $allowed_files = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];


        $num_files = count($_FILES['file_idea']['name']);

        foreach($_FILES['file_idea']['name'] as $i => $fileName) {
            if($num_files > 3) {
                throw new \Exception('Le nombre maximum de fichier à été dépassé ! (maximum 3 fichiers/idées)');
            }
        }

        for($i = 0; $i < $num_files; $i++) {

            if (isset($_FILES['file_idea']) && in_array($_FILES['file_idea']['type'][$i], $allowed_files)) {
                if (filesize($_FILES['file_idea']['tmp_name'][$i]) > 10000000) {
                    throw new \Exception('Le fichier est trop lourd');
                }
                
                if (!in_array($_FILES['file_idea']['type'][$i], $allowed_files)) {
                    throw new \Exception('Le fichier n\'a pas le bon type');
                }
                
                $file = new Files;
                $idea->setHasFile(true);
                $file->setRelatedIdeaId($idea);
                $file->setUploadDate(new \DateTime());
                $idea->addFile($file);
                $file->setNameFile($_FILES['file_idea']['name'][$i]);
                $this->entityManager->persist($file);
                $this->entityManager->flush();
                $path = '../upload_files';
                $tmpFilePath = $_FILES['file_idea']['tmp_name'][$i];
                $name = basename($_FILES['file_idea']['name'][$i]);
                move_uploaded_file($tmpFilePath, $path . '/' . $file->getId());
            } else {
                $idea->setHasFile(false);
            $this->entityManager->persist($idea);
            $this->entityManager->flush();
        }
    }
        
        return new RedirectResponse('/idea/' . $idea->getId());
    }
    // Récuperer les données de l'idée
    // (titre, détails, choix pour les mesures que l'utilisateur prend,
    // du financement et du quel responsable à envoyer le projet)
    // Tout ça dans les tables idea et manager !
    // Enfin faire en sorte de l'afficher sur une page récapitulative avec les infos de l'utilisateur et de l'idée.

    #[Route('/idea/{id}')]
    public function display_idea(Request $request, $id)
    {
        Functions::checkUserSession($this->accountRepository);

        // Affichage de l'idée

        $idea = $this->ideaRepository->find($id);
        $ideas = [];
        foreach ($idea as $_idea) {
            $ideas[] = [
                'title_idea' => $_idea->getTitle(),
                'details_idea' => $_idea->getDetails(),
                'choice_mesures' => $_idea->getChoiceMesures(),
                'details_mesures' => $_idea->getDetailsMesures(),
                'choice_funding' => $_idea->getChoiceFunding(),
                'funding_details' => $_idea->getDetailsFunding(),
                'team' => $_idea->getTeam(),
                'author_id' => $_idea->getAuthor()->getId(),
                'idea_id' => $_idea->getId(),
                'is_archived' => $idea->isArchived(),
                'role' => $_SESSION['role'],
                'validator_givenname' => $idea->getValidator()->getGivenName(),
                'validator_familyname' => $idea->getValidator()->getFamilyName(),
                'first_name' => $_idea->getAuthor()->getGivenName(),
                'family_name' => $_idea->getAuthor()->getFamilyName(),
                'creationDateTime' => $_idea->getCreationDateTime(),
                'state_idea' => $_idea->getState(),
                'validator_givenname' => $idea->getValidator()->getGivenName(),
                'validator_familyname' => $idea->getValidator()->getFamilyName(),
            ];
        }
        $like = 0;
        $dislike = 0;
        $vote_value = -1;
        $votes = $idea->getVotes();
        foreach ($votes as $vote) {
            if ($vote->getValue() == 1) {
                $like++;
            } elseif ($vote->getValue() == 0) {
                $dislike++;
            }

            if ($vote->getAuhtor()->getId() == $_SESSION['account_id']) {
                $vote_value = $vote->getValue();
            }
        }
        // Commentaires de l'idée

        $author = $this->accountRepository->find($_SESSION['account_id']);
        if (isset($_POST['send_comment'])) {
            $comment = new Comment;
            $comment->setMessage($request->request->get('content_commentary'));
            $idea->addComment($comment);
            $comment->setAuthor($author);
            $comment->setCreationDateTime(new \DateTime());
            $this->entityManager->persist($comment);
            $this->entityManager->flush();
        }

        $comments = $idea->getComments();
        $_comments = [];
        foreach ($comments as $commentary) {
            $_comments[] = [
                'comment_id' => $commentary->getId(),
                'comment_idea_id' => $commentary->getRelatedIdea(),
                'author_givenname' => $commentary->getAuthor()->getGivenName(),
                'author_familyname' => $commentary->getAuthor()->getFamilyName(),
                'content_comment' => $commentary->getMessage(),
                'create_comment' => $commentary->getCreationDateTime(),
            ];
        }

        $answer = $this->answerRepository->findAll();
        $answers = [];
        foreach ($answer as $_answer) {
            $answers[] = [
                'answer_content' => $_answer->getAnswerContent(),
                'related_comment_id' => $_answer->getRelatedCommentId()->getId(),
                'author_givenname' => $_answer->getAnswerAuthorId()->getGivenName(),
                'author_familyname' => $_answer->getAnswerAuthorId()->getFamilyName(),
            ];
        }

        $data = [
            'title_idea' => $idea->getTitle(),
            'details_idea' => $idea->getDetails(),
            'choice_mesures' => $idea->getChoiceMesures(),
            'details_mesures' => $idea->getDetailsMesures(),
            'choice_funding' => $idea->getChoiceFunding(),
            'funding_details' => $idea->getDetailsFunding(),
            'idea_id' => $idea->getId(),
            'team' => $idea->getTeam(),
            'author_id' => $idea->getAuthor()->getId(),
            'is_admin' => in_array('admin', $_SESSION['role']) ? 'true' : 'false',
            'state' => $idea->getState(),
            'user_id' => $_SESSION['account_id'],
            'is_author' => $author->isAuthor() ? 'true' : 'false',
            'validator_id' => $idea->getValidator() != null ? $idea->getValidator()->getId() : '',
            'validator_givenname' => $idea->getValidator() != null ? $idea->getValidator()->getGivenName() : '',
            'validator_familyname' => $idea->getValidator() != null ? $idea->getValidator()->getFamilyName() : '',
            'ideas' => $ideas,
            'count_like' => $like,
            'count_dislike' => $dislike,
            'vote_value' => $vote_value,
            'comments' => $_comments,
            'answers' => $answers,
        ];

        return $this->render('/idea/recap_idea.html.twig', $data);
    }

    #[Route('/idea/{id}/valid')]
    public function validateIdea(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $idea = $this->ideaRepository->find($id);
        $validatorId = $this->accountRepository->find($_SESSION['account_id']);
        $idea->setValidator($validatorId);
        $idea->setState('in_progress');
        $this->entityManager->persist($idea);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/manage_ideas');
    }

    #[Route('/idea/{id}/refuse')]
    public function refusedIdea(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $idea = $this->ideaRepository->find($id);

        $idea->setState('refused');
        $this->entityManager->persist($idea);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/manage_ideas');
    }

    #[Route('/idea/{id}/wait')]
    public function waiting(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $idea = $this->ideaRepository->find($id);

        $idea->setState('waiting_approval');
        $idea->setValidator(null);
        $idea->setArchived(false);
        $this->entityManager->persist($idea);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/manage_ideas');
    }

    #[Route('/idea/{id}/archived')]
    public function archivedIdea(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $idea = $this->ideaRepository->find($id);

        $idea->setArchived(true);
        $this->entityManager->persist($idea);
        $this->entityManager->flush();
        return new RedirectResponse('/idea/' .$id);
    }

    #[Route('/idea/{id}/delete')]
    public function deleteIdea(Request $request, $id)
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $idea = $this->ideaRepository->find($id);
        $comments = $idea->getComments();
        $votes = $idea->getVotes();
        $files = $idea->getFiles();

        foreach ($comments as $comment) {
            $answers = $comment->getAnswers();
            foreach ($answers as $answer) {
                $this->entityManager->remove($answer);
            }
            $this->entityManager->remove($comment);
        }

        foreach ($votes as $vote) {
            $this->entityManager->remove($vote);
        }

        foreach ($files as $file) {
            $this->entityManager->remove($file);
        }

        $this->entityManager->remove($idea);
        $this->entityManager->flush();

        return $this->redirect('/home');
    }

    #[Route('/idea/{id}/comment/{comment_id}/delete')]
    public function deleteComment(Request $request, $id, $comment_id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);
        Functions::checkRoleAdmin();

        $comment = $this->commentRepository->find($comment_id);
        $idea = $this->ideaRepository->find($id);
        
        foreach ($comment as $comments) {
            $answers = $comments->getAnswers();
            foreach ($answers as $answer) {
                $this->entityManager->remove($answer);
            }
            $this->entityManager->remove($comment);
        }

        $comment->setRelatedIdea($idea);
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
        return new RedirectResponse('/idea/' .$id);
    }

    #[Route('/idea/{id}/idea_realized')]
    public function realizedIdea(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);

        $idea = $this->ideaRepository->find($id);
        $idea->setState('is_realized');
        $this->entityManager->persist($idea);
        $this->entityManager->flush();
        return new RedirectResponse('/idea/' . $id);
    }

    #[Route('/idea/{id}/idea_not_realized')]
    public function notrealizedIdea(Request $request, $id): RedirectResponse
    {
        Functions::checkUserSession($this->accountRepository);

        $idea = $this->ideaRepository->find($id);
        $idea->setState('is_not_realized');
        $this->entityManager->persist($idea);
        $this->entityManager->flush();
        return new RedirectResponse('/idea/' . $id);
    }

    #[Route('/idea/{id}/vote_liked')]
    public function voteLiked(Request $request, $id)
    {
        Functions::checkUserSession($this->accountRepository);

        $vote_auhtor = $this->accountRepository->find($_SESSION['account_id']);
        $idea = $this->ideaRepository->find($id);
        $_vote = $this->voteRepository->findOneBy(['related_idea_id' => $id, 'auhtor' => $_SESSION['account_id']]);
        if ($_vote == null) {
            $vote = new Vote;
            $vote->setAuhtor($vote_auhtor);
            $vote->setValue(1);
            $vote->setRelatedIdeaId($idea);
            $this->entityManager->persist($vote);
            $this->entityManager->flush();
        } else {
            $this->entityManager->remove($_vote);
            $this->entityManager->flush();
        }
        return $this->redirect('/idea/' . $id);
    }

    #[Route('/idea/{id}/vote_disliked')]
    public function voteDisliked(Request $request, $id)
    {
        Functions::checkUserSession($this->accountRepository);

        $vote_auhtor = $this->accountRepository->find($_SESSION['account_id']);
        $idea = $this->ideaRepository->find($id);
        $_vote = $this->voteRepository->findOneBy(['related_idea_id' => $id, 'auhtor' => $_SESSION['account_id']]);
        if ($_vote == null) {
            $vote = new Vote;
            $vote->setAuhtor($vote_auhtor);
            $vote->setValue(0);
            $vote->setRelatedIdeaId($idea);
            $this->entityManager->persist($vote);
        } else {
            $this->entityManager->remove($_vote);
        }
        $this->entityManager->flush();
        return $this->redirect('/idea/' . $id);
    }

}
