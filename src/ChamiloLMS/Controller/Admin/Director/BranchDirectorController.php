<?php
/* For licensing terms, see /license.txt */

namespace ChamiloLMS\Controller\Admin\Director;

use ChamiloLMS\Controller\CommonController;
use ChamiloLMS\Form\BranchType;
use ChamiloLMS\Form\JuryType;
use Entity;
use Silex\Application;
use Symfony\Component\Form\Extension\Validator\Constraints\FormValidator;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * Class RoleController
 * @todo @route and @method function don't work yet
 * @package ChamiloLMS\Controller
 * @author Julio Montoya <gugli100@gmail.com>
 */
class BranchDirectorController extends CommonController
{
    /**
     * @Route("/")
     * @Method({"GET"})
     */
    public function indexAction()
    {
        $token = $this->get('security')->getToken();

        if (null !== $token) {
            $user = $token->getUser();
            $userId = $user->getUserId();
        }

        $options = array(
            'decorate' => true,
            'rootOpen' => '<ul>',
            'rootClose' => '</ul>',
            'childOpen' => '<li>',
            'childClose' => '</li>',
            'nodeDecorator' => function($row) {
                //$addChildren = '<a class="btn" href="'.$this->createUrl('add_from_parent_link', array('id' => $row['id'])).'">Add children</a>';
                $readLink = $row['branchName'].' <a class="btn" href="'.$this->createUrl('read_link', array('id' => $row['id'])).'">Assign users</a>';
                return $readLink;
            }
            //'representationField' => 'slug',
            //'html' => true
        );

        // @todo add director filters
        $repo = $this->getRepository();

        $query = $this->getManager()
            ->createQueryBuilder()
            ->select('node')
            ->from('Entity\BranchSync', 'node')
            ->orderBy('node.root, node.lft', 'ASC')
            ->getQuery();

        $htmlTree = $repo->buildTree($query->getArrayResult(), $options);
        $this->get('template')->assign('tree', $htmlTree);
        $this->get('template')->assign('links', $this->generateLinks());
        $response = $this->get('template')->render_template($this->getTemplatePath().'list.tpl');
        return new Response($response, 200, array());
    }

    /**
    *
    * @Route("/{id}", requirements={"id" = "\d+"})
    * @Method({"GET"})
    */
    public function readAction($id)
    {
        $template = $this->get('template');
        $request = $this->getRequest();

        $template->assign('links', $this->generateLinks());
        $repo = $this->getRepository();

        $item = $this->getEntity($id);
        $template->assign('item', $item);

        $form = $this->get('form.factory')->create(new JuryType());

        if ($request->getMethod() == 'POST') {
            $form->bind($this->getRequest());

            if ($form->isValid()) {

            }
        }

        $template->assign('form', $form->createView());
        $response = $template->render_template($this->getTemplatePath().'read.tpl');
        return new Response($response, 200, array());
    }

    private function saveUsers()
    {

    }

    private function getUserFormType($users)
    {

    }



    protected function getControllerAlias()
    {
        return 'branch_director.controller';
    }

    /**
    * {@inheritdoc}
    */
    protected function getTemplatePath()
    {
        return 'admin/director/branches/';
    }

    /**
     * @return \Entity\Repository\BranchSyncRepository
     */
    protected function getRepository()
    {
        return $this->get('orm.em')->getRepository('Entity\BranchSync');
    }

    /**
     * {@inheritdoc}
     */
    protected function getNewEntity()
    {
        return new Entity\BranchSync();
    }
}
