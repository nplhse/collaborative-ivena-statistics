<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Domain\Entity\PostComment;
use App\Content\Infrastructure\Repository\PostCategoryRepository;
use App\Content\Infrastructure\Repository\PostCommentRepository;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Content\Infrastructure\Repository\PostTagRepository;
use App\Content\UI\Http\DTO\BlogListQueryParametersDTO;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly PostCategoryRepository $categoryRepository,
        private readonly PostTagRepository $tagRepository,
        private readonly PostCommentRepository $postCommentRepository,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/blog', name: 'app_blog_index', methods: ['GET'])]
    public function index(#[MapQueryString] BlogListQueryParametersDTO $query): Response
    {
        return $this->render('@Content/blog/index.html.twig', [
            'paginator' => $this->postRepository->getPublishedPaginator($query),
            'pagination_route' => 'app_blog_index',
            'pagination_route_params' => [],
            'list_title' => 'blog.list.all_posts',
            'list_title_params' => [],
            ...$this->buildSidebarData(),
        ]);
    }

    #[Route('/blog/category/{slug}', name: 'app_blog_category', methods: ['GET'])]
    public function category(string $slug, #[MapQueryString] BlogListQueryParametersDTO $query): Response
    {
        $category = $this->categoryRepository->findOneBySlug($slug);
        if (!$category instanceof \App\Content\Domain\Entity\PostCategory) {
            throw $this->createNotFoundException($this->translator->trans('error.blog.category_not_found'));
        }

        return $this->render('@Content/blog/index.html.twig', [
            'paginator' => $this->postRepository->getPublishedByCategorySlugPaginator($slug, $query),
            'pagination_route' => 'app_blog_category',
            'pagination_route_params' => ['slug' => $slug],
            'activeCategory' => $category,
            'list_title' => 'blog.list.category',
            'list_title_params' => ['name' => $category->getName()],
            ...$this->buildSidebarData(),
        ]);
    }

    #[Route('/blog/tag/{slug}', name: 'app_blog_tag', methods: ['GET'])]
    public function tag(string $slug, #[MapQueryString] BlogListQueryParametersDTO $query): Response
    {
        $tag = $this->tagRepository->findOneBySlug($slug);
        if (!$tag instanceof \App\Content\Domain\Entity\PostTag) {
            throw $this->createNotFoundException($this->translator->trans('error.blog.tag_not_found'));
        }

        return $this->render('@Content/blog/index.html.twig', [
            'paginator' => $this->postRepository->getPublishedByTagSlugPaginator($slug, $query),
            'pagination_route' => 'app_blog_tag',
            'pagination_route_params' => ['slug' => $slug],
            'activeTag' => $tag,
            'list_title' => 'blog.list.tag',
            'list_title_params' => ['name' => $tag->getName()],
            ...$this->buildSidebarData(),
        ]);
    }

    #[Route('/blog/rss.xml', name: 'app_blog_rss', methods: ['GET'])]
    public function rss(): Response
    {
        $xml = $this->renderView('@Content/blog/rss.xml.twig', [
            'posts' => $this->postRepository->findPublishedForIndex(30),
            'buildDate' => new \DateTimeImmutable('now'),
        ]);

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    #[Route('/blog/{slug}', name: 'app_blog_show', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $post = $this->postRepository->findPublishedBySlug($slug);
        if (!$post instanceof \App\Content\Domain\Entity\Post) {
            throw $this->createNotFoundException($this->translator->trans('error.blog.post_not_found'));
        }

        return $this->render('@Content/blog/show.html.twig', [
            'post' => $post,
            'comments' => $this->postCommentRepository->findRootCommentsForPost($post),
            'previous_post' => $this->postRepository->findPreviousPublishedPost($post),
            'next_post' => $this->postRepository->findNextPublishedPost($post),
            ...$this->buildSidebarData(),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/blog/{slug}/comments', name: 'app_blog_comment_create', methods: ['POST'])]
    public function createComment(string $slug, Request $request): RedirectResponse
    {
        $post = $this->postRepository->findPublishedBySlug($slug);
        if (!$post instanceof \App\Content\Domain\Entity\Post) {
            throw $this->createNotFoundException($this->translator->trans('error.blog.post_not_found'));
        }

        $postId = $post->getId();
        if (null === $postId) {
            throw $this->createNotFoundException($this->translator->trans('error.blog.post_not_found'));
        }

        if (!$this->isCsrfTokenValid('comment_'.$postId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException($this->translator->trans('error.blog.invalid_csrf'));
        }

        $content = trim((string) $request->request->get('content'));
        if ('' === $content) {
            $this->addFlash('error', $this->translator->trans('flash.blog.comment.empty'));

            return $this->redirectToRoute('app_blog_show', ['slug' => $slug]);
        }

        $comment = new PostComment();
        $comment->setPost($post);
        $comment->setContent($content);

        $author = $this->getUser();
        if (!$author instanceof User) {
            throw $this->createAccessDeniedException($this->translator->trans('error.blog.user_not_found'));
        }
        $comment->setAuthor($author);

        $parentId = $request->request->getInt('parent_id', 0);
        if ($parentId > 0) {
            $parent = $this->postCommentRepository->find($parentId);
            if ($parent instanceof PostComment && $parent->getPost()?->getId() === $post->getId()) {
                $comment->setParent($parent);
            }
        }

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('flash.blog.comment.created'));

        return $this->redirectToRoute('app_blog_show', ['slug' => $slug], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{
     *   latest_posts: list<\App\Content\Domain\Entity\Post>,
     *   sidebar_categories: list<array{category: \App\Content\Domain\Entity\PostCategory, postCount: int}>,
     *   sidebar_tags: list<array{tag: \App\Content\Domain\Entity\PostTag, postCount: int}>
     * }
     */
    private function buildSidebarData(): array
    {
        return [
            'latest_posts' => $this->postRepository->findPublishedForIndex(5),
            'sidebar_categories' => $this->categoryRepository->findSidebarItems(),
            'sidebar_tags' => $this->tagRepository->findSidebarItems(),
        ];
    }
}
