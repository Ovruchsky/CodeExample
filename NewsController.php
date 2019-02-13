<?php

namespace AppBundle\Controller;


use AppBundle\Entity\Comments;
use AppBundle\Form\CommentForm;
use AppBundle\Form\UserForm;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;


class NewsController extends Controller
{
    /**
     * @Route("/")
     */
    public function AllNewsAction(Request $request)
    {
        $manager = $this->getDoctrine()->getManager();
        $repository = $manager->getRepository('AppBundle:News');
        $page = $request->query->get("page") && $request->query->get("page") > 1 ? $request->query->get("page") : 1;
        $locale = $request->getLocale();

        $news = $repository->findBy(
            ['lang' => $locale, 'status' => 1],
            ['id' => 'DESC'],
            9,
            9 * ($page - 1)
        );


        $query = $manager->createQuery(
            'SELECT comments
        FROM AppBundle:News comments
        WHERE comments.comments_num > 1
        AND  comments.lang = :locale
        ORDER BY comments.id DESC '
        )->setParameter('locale', $locale)->setMaxResults(3);
        $topNews = $query->execute();


        $query = $manager->createQueryBuilder();
        $query->select('count(s)');
        $query->where('s.status = 1');
        $query->andwhere("s.lang = '" . $locale . "'");
        $query->from('AppBundle:News', 's');
        $countNews = $query->getQuery()->getSingleScalarResult();

        $pages = [
            'page' => $page,
            'total_pages' => $countNews / 9,
            'total_news' => $countNews,
            'url' => 'news_teaser'
        ];
        return $this->render('news/news.html.twig', [
            'news' => $news,
            'topNews' => $topNews,
            'pagination' => $pages,
        ]);
    }


    /**
     * @Route("/news/{id}", name="news_show")
     */
    public function NewsPageAction(Request $request, $id)
    {
        $manager = $this->getDoctrine()->getManager();
        $repository = $manager->getRepository('AppBundle:News');
        $news = $repository->find($id);
        $commentRep = $manager->getRepository('AppBundle:Comments');
        $comment = $commentRep->findBy(
            ['status' => 1],
            ['id' => 'DESC']
        );
        $userRep = $manager->getRepository('AppBundle:User');

        $setComments = new Comments();
        $form = $this->createForm(CommentForm::class, $setComments);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $userRep->find($this->getUser());
            $num = $news->getCommentsNum();
            $news->setCommentsNum($num + 1);
            $userName = $this->get('security.token_storage')->getToken()->getUser()->getUsername();
            $setComments->setUsername($userName);
            $setComments->setUserId($user);
            $setComments->setUserAv($user->getAvatar());
            $setComments->setNewsId($news->getid());
            $setComments->setStatus(1);
            $setComments->setHidden(0);
            $setComments->setTime(new \DateTime('now'));
            $manager->persist($setComments);
            $manager->flush();
            return $this->redirect($request->getUri());
        }

        if (!$news) {
            throw new NotFoundHttpException('No results found for id ' . $id);
        } else {
            return $this->render('news/newsShow.html.twig', array(
                'news' => $news,
                'comment' => $comment,
                'form' => $form->createView()
            ));
        }
    }

    /**
     * @Route("/news/{id}/delete", name="delete_comment")
     */
    public function deleteCommentAction(Request $request, $id)
    {
        $manager = $this->getDoctrine()->getManager();
        $point = $manager->getRepository('AppBundle:Comments')->find($id);
        $commentAuthor = $point->getUsername();
        if (!$point) {
            throw new NotFoundHttpException('No results found for id ' . $id);
        } else if (
            $this->isGranted('IS_AUTHENTICATED_FULLY') and
            $this->get('security.token_storage')->getToken()->getUser()->getUsername() == $commentAuthor or
            $this->isGranted('ROLE_ADMIN')) {
            $id = $point->getNewsId();
            $commentNum = $manager->getRepository('AppBundle:News')->find($id);
            $num = $commentNum->getCommentsNum();
            if ($num > 0) {
                $commentNum->setCommentsNum($num - 1);
            }
            $manager->remove($point);
            $manager->flush();

            return $this->redirect($request->headers->get('referer'));

        } else {
            throw new NotFoundHttpException('You have no rights to delete comment ' . $id);
        }

    }


    /**
     * @Route("/profile/{username}", name="userPage")
     */
    public function UserProfileAction(Request $request, $username)
    {
        $profile = $this->getDoctrine()->getRepository('AppBundle:User')->findOneBy(['username' => $username]);
        $avatar = $profile->getAvatar();
        $commentAuthor = $this->getDoctrine()->getRepository('AppBundle:Comments')->findBy(['username' => $username]);
        if (!$profile) {
            throw new NotFoundHttpException('No results found for ' . $username);
        } else {
            $form = $this->createForm(UserForm::class, $profile);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                if ($profile->getAvatar()) {
                    $Photo = $form->get('avatar')->getData();
                    $fileName = $this->generateUniqueFileName() . '.' . $Photo->guessExtension();
                    $Photo->move(
                        $this->getParameter('photo_directory'),
                        $fileName
                    );
                    $profile->setAvatar($fileName);
                    foreach ($commentAuthor as $comment) {
                        $comment->setUserAv($fileName);
                    }
                    $fs = new Filesystem();
                    if ($avatar != 'default/default.png') {
                        $path = $this->getParameter('photo_directory') . '/' . $avatar;
                        $fs->remove(array($path));
                    }
                } else {
                    $profile->setAvatar($avatar);
                }

                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->persist($profile);
                $entityManager->flush();

                return $this->redirectToRoute('userPage', array('username' => $username));
            }

            return $this->render('user/userPage.html.twig', array(
                'form' => $form->createView(),
                'user' => $profile
            ));
        }
    }

    private function generateUniqueFileName()
    {
        return md5(uniqid());
    }
}

