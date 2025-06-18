<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\Machine;
use App\Entity\Notification;
use App\Entity\Panne;
use App\Entity\Technicien;
use App\Form\MachineType;
use App\Repository\AdminRepository;
use App\Repository\MachinePanneRepository;
use App\Repository\MachineRepository;
use App\Repository\NotificationRepository;
use App\Repository\PanneRepository;
use App\Repository\TechnicienRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class AdminDashboardController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    // === DASHBOARD ===
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(MachineRepository $machineRepository, NotificationRepository $notificationRepo): Response
    {
        $machines = $machineRepository->findAll();
        $notifications = $notificationRepo->findUnreadAdminNotifications();

        return $this->render('machine/index.html.twig', [
            'machines' => $machines,
            'notifications' => $notifications,
        ]);
    }

    #[Route('/admin/kpis', name: 'admin_kpi_dashboard')]
    public function kpiDashboard(
        MachineRepository $machineRepository,
        PanneRepository $panneRepository,
        NotificationRepository $notificationRepo
    ): Response {
        $notifications = $notificationRepo->findUnreadAdminNotifications();

        return $this->render('admin/kpi_dashboard.html.twig', [
            'totalMachines' => $machineRepository->count([]),
            'activeMachines' => $machineRepository->count(['status' => 'active']),
            'inactiveMachines' => $machineRepository->count(['status' => 'inactive']),
            'totalPannes' => $panneRepository->count([]),
            'pannesEnCours' => $panneRepository->count(['statut' => 'en cours']),
            'pannesResolues' => $panneRepository->count(['statut' => 'résolue']),
            'notifications' => $notifications,
        ]);
    }

    // === ADMIN & TECHNICIEN USER MANAGEMENT ===

    #[Route('/admin/utilisateurs/admins', name: 'admin_list')]
    public function listAdmins(AdminRepository $adminRepository): Response
    {
        return $this->render('admin/admin_list.html.twig', [
            'admins' => $adminRepository->findAll(),
        ]);
    }

    #[Route('/admin/utilisateurs/techniciens', name: 'admin_technicien_list')]
    public function listTechniciens(TechnicienRepository $technicienRepository): Response
    {
        return $this->render('admin/technicien_list.html.twig', [
            'techniciens' => $technicienRepository->findAll(),
        ]);
    }

    #[Route('/admin/technicien/{id}/toggle-ban', name: 'admin_toggle_technicien_ban', methods: ['POST'])]
    public function toggleBanTechnicien(Request $request, Technicien $technicien): JsonResponse
    {
        $technicien->setIsBanned(!$technicien->isBanned());
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'isBanned' => $technicien->isBanned(),
            'nom' => $technicien->getNom() . ' ' . $technicien->getPrenom()
        ]);
    }


    // === MACHINES ===

    #[Route('/admin/machine/create', name: 'admin_machine_create')]
    public function create(Request $request): Response
    {
        $machine = new Machine();
        $form = $this->createForm(MachineType::class, $machine);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($machine);
            $this->entityManager->flush();

            $this->addFlash('success', 'Machine créée avec succès !');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('machine/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/admin/machine/{id}/edit', name: 'admin_machine_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Machine $machine): Response
    {
        $form = $this->createForm(MachineType::class, $machine);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Machine modifiée avec succès !');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('machine/edit.html.twig', [
            'form' => $form->createView(),
            'machine' => $machine,
        ]);
    }

    #[Route('/admin/machine/{id}/delete', name: 'admin_machine_delete')]
    public function delete(Machine $machine): Response
    {
        $this->entityManager->remove($machine);
        $this->entityManager->flush();

        $this->addFlash('success', 'Machine supprimée avec succès !');
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/search-machine', name: 'admin_search_machine')]
    public function searchMachine(Request $request, MachineRepository $machineRepository): JsonResponse
    {
        $query = $request->query->get('query', '');

        $machines = $machineRepository->createQueryBuilder('m')
            ->where('m.nom LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->getQuery()
            ->getResult();

        $data = [];

        foreach ($machines as $machine) {
            $data[] = [
                'id' => $machine->getId(),
                'nom' => $machine->getNom(),
                'status' => $machine->getStatus(),
            ];
        }

        return new JsonResponse($data);
    }

    // === PANNES ===

    #[Route('/admin/pannes', name: 'admin_panne_list')]
    public function panneList(PanneRepository $panneRepository, NotificationRepository $notificationRepo): Response
    {
        return $this->render('admin/panne_list.html.twig', [
            'pannes' => $panneRepository->findAll(),
            'notifications' => $notificationRepo->findUnreadAdminNotifications(),
        ]);
    }

    #[Route('/admin/panne/{id}', name: 'admin_panne_show')]
    public function showPanne(Panne $panne, NotificationRepository $notificationRepo): Response
    {
        return $this->render('admin/panne_show.html.twig', [
            'panne' => $panne,
            'notifications' => $notificationRepo->findUnreadAdminNotifications(),
        ]);
    }

    // === PROFILE ===

    #[Route('/admin/profile/upload-image', name: 'admin_update_profile_image', methods: ['POST'])]
    public function uploadProfileImage(Request $request): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();
        $file = $request->files->get('profileImage');

        if ($file && $file->isValid()) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower($file->guessExtension());

            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('danger', 'Format d\'image non pris en charge.');
                return $this->redirectToRoute('user_profile');
            }

            $filename = uniqid('profile_', true) . '.' . $extension;
            $file->move($this->getParameter('profile_images_dir'), $filename);

            $admin->setProfileImage('uploads/profile/' . $filename);
            $this->entityManager->flush();

            $this->addFlash('success', 'Photo de profil mise à jour avec succès.');
        } else {
            $this->addFlash('danger', 'Erreur lors du téléchargement de l\'image.');
        }

        return $this->redirectToRoute('user_profile');
    }

    #[Route('/admin/change-password', name: 'admin_change_password')]
    public function changePassword(Request $request): Response
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('new_password');
            $confirmPassword = $request->request->get('confirm_password');

            if ($newPassword === $confirmPassword && !empty($newPassword)) {
                $admin->setPassword($newPassword); // ⚠️ Plaintext for test only
                $this->entityManager->flush();
                $this->addFlash('success', 'Mot de passe mis à jour avec succès.');
                return $this->redirectToRoute('user_profile');
            }

            $this->addFlash('danger', 'Les mots de passe ne correspondent pas ou sont vides.');
        }

        return $this->render('admin/change_password.html.twig');
    }

    // === NOTIFICATIONS ===

    #[Route('/admin/notification/{id}/read', name: 'admin_notification_read')]
    public function markNotificationAsRead(Notification $notification): Response
    {
        if (!$notification) {
            throw new NotFoundHttpException("Notification introuvable");
        }

        $notification->setIsRead(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'Notification marquée comme lue.');
        return $this->redirectToRoute('admin_dashboard');
    }
}
