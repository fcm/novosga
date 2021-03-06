<?php
namespace modules\sga\atendimento;

use DateTime;
use Exception;
use Novosga\App;
use Novosga\Context;
use Novosga\Util\Arrays;
use Novosga\Util\DateUtil;
use Novosga\Business\AtendimentoBusiness;
use Novosga\Business\FilaBusiness;
use Novosga\Controller\ModuleController;
use Novosga\Model\Atendimento;
use Novosga\Model\Modulo;
use Novosga\Model\Util\UsuarioSessao;
use Novosga\Http\JsonResponse;

/**
 * AtendimentoController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class AtendimentoController extends ModuleController {
    
    private $_atendimentoAtual;
    private $atendimentoBusiness;
    
    public function __construct(App $app, Modulo $modulo) {
        parent::__construct($app, $modulo);
        $this->atendimentoBusiness = new AtendimentoBusiness($this->em());
    }
    
    public function index(Context $context) {
        $usuario = $context->getUser();
        $unidade = $context->getUnidade();
        if (!$usuario || !$unidade) {
            $this->app()->gotoHome();
        }
        $this->app()->view()->set('time', time() * 1000);
        $this->app()->view()->set('unidade', $unidade);
        $this->app()->view()->set('atendimento', $this->atendimentoAndamento($usuario));
        $this->app()->view()->set('servicos', $usuario->getServicos());
        $this->app()->view()->set('servicosIndisponiveis', $usuario->getServicosIndisponiveis());
        $tiposAtendimento = array(
            UsuarioSessao::ATEND_TODOS => _('Todos'), 
            UsuarioSessao::ATEND_CONVENCIONAL => _('Convencional'), 
            UsuarioSessao::ATEND_PRIORIDADE => _('Prioridade')
        );
        $this->app()->view()->set('tiposAtendimento', $tiposAtendimento);
        $this->app()->view()->set('labelTipoAtendimento', $tiposAtendimento[$usuario->getTipoAtendimento()]);
        $this->app()->view()->set('local', $usuario->getLocal());
        $this->app()->view()->set('localCookie', $context->cookie()->get('local'));
        $this->app()->view()->set('tipoAtendimentoCookie', $context->cookie()->get('tipo'));
    }
    
    public function set_local(Context $context) {
        $numero = (int) $context->request()->post('local');
        $tipo = (int) $context->request()->post('tipo');
        if ($numero) {
            $context->cookie()->set('local', $numero);
            $context->cookie()->set('tipo', $tipo);
            $context->getUser()->setLocal($numero);
            $context->getUser()->setTipoAtendimento($tipo);
            $context->setUser($context->getUser());
        }
        $this->app()->redirect('index');
    }
    
    private function atendimentosQuery(UsuarioSessao $usuario) {
        $ids = array();
        $servicos = $usuario->getServicos();
        foreach ($servicos as $s) {
            $ids[] = $s->getServico()->getId();
        }
        // se nao tiver servicos, coloca id invalido so para nao dar erro no sql
        if (empty($ids)) {
            $ids[] = 0;
        }
        
        $filaBusiness = new FilaBusiness($this->em());
        
        $query = $filaBusiness
                    ->atendimento($usuario->getUnidade(), $ids, $usuario->getTipoAtendimento())
                    ->getQuery()
        ;
        return $query;
    }
    
    private function atendimentos(UsuarioSessao $usuario) {
        return $this->atendimentosQuery($usuario)->getResult();
    }
    
    private function atendimentoAndamento(UsuarioSessao $usuario) {
        if (!$this->_atendimentoAtual) {
            $status = array(
                AtendimentoBusiness::CHAMADO_PELA_MESA,
                AtendimentoBusiness::ATENDIMENTO_INICIADO,
                AtendimentoBusiness::ATENDIMENTO_ENCERRADO
            );
            $query = $this->em()->createQuery("SELECT e FROM Novosga\Model\Atendimento e WHERE e.usuario = :usuario AND e.status IN (:status)");
            $query->setParameter('usuario', $usuario->getId());
            $query->setParameter('status', $status);
            $this->_atendimentoAtual = $query->getOneOrNullResult();
        }
        return $this->_atendimentoAtual;
    }
    
    public function get_fila(Context $context) {
        $response = new JsonResponse();
        $unidade = $context->getUnidade();
        if ($unidade) {
            // fila de atendimento do atendente atual
            $response->data = array();
            $atendimentos = $this->atendimentos($context->getUser());
            foreach ($atendimentos as $atendimento) {
                // minimal data
                $response->data[] = $atendimento->toArray(true);
            }
            $response->success = true;
        }
        return $response;
    }
    
    /**
     * Chama ou rechama o próximo da fila
     * @param Novosga\Context $context
     */
    public function chamar(Context $context) {
        $response = new JsonResponse();
        try {
            if (!$context->request()->isPost()) {
                throw new Exception(_('Somente via POST'));
            }
            $attempts = 0;
            $maxAttempts = 5;
            $proximo = null;
            $success = false;
            $usuario = $context->getUser();
            $unidade = $context->getUnidade();
            if (!$usuario) {
                throw new Exception(_('Nenhum usuário na sessão'));
            }
            // verifica se ja esta atendendo alguem
            $atual = $this->atendimentoAndamento($usuario);
            // se ja existe um atendimento em andamento (chamando senha novamente)
            if ($atual) {
                $success = true;
                $proximo = $atual;
            } else {
                do {
                    $query = $this->atendimentosQuery($usuario);
                    $query->setMaxResults(1);
                    $proximo = $query->getOneOrNullResult();
                    if ($proximo) {
                        $proximo->setUsuario($context->getUser()->getWrapped());
                        $proximo->setLocal($context->getUser()->getLocal());
                        $proximo->setStatus(AtendimentoBusiness::CHAMADO_PELA_MESA);
                        $proximo->setDataChamada(new DateTime());
                        // atualiza o proximo da fila
                        $query = $this->em()->createQuery("
                            UPDATE 
                                Novosga\Model\Atendimento e 
                            SET 
                                e.usuario = :usuario, e.local = :local, e.status = :novoStatus, e.dataChamada = :data
                            WHERE 
                                e.id = :id AND e.status = :statusAtual
                        ");
                        $query->setParameter('usuario', $proximo->getUsuario()->getId());
                        $query->setParameter('local', $proximo->getLocal());
                        $query->setParameter('novoStatus', $proximo->getStatus());
                        $query->setParameter('data', $proximo->getDataChamada());
                        $query->setParameter('id', $proximo->getId());
                        $query->setParameter('statusAtual', AtendimentoBusiness::SENHA_EMITIDA);
                        /* 
                         * caso entre o intervalo do select e o update, o proximo ja tiver sido chamado
                         * a consulta retornara 0, entao tenta pegar o proximo novamente (outro)
                         */
                        $success = $query->execute() > 0;
                        $attempts++;
                    } else {
                        // nao existe proximo
                        break;
                    }
                } while (!$success && $attempts < $maxAttempts);
            }
            // response
            if (!$success) {
                if (!$proximo) {
                    throw new Exception(_('Fila vazia'));
                } else {
                    throw new Exception(_('Já existe um atendimento em andamento'));
                }
            }
            $response->success = $success;
            $this->atendimentoBusiness->chamarSenha($unidade, $proximo);
            $response->data = $proximo->toArray();
        } catch (Exception $e) {
            $response->success = false;
            $response->message = $e->getMessage();
        }
        return $response;
    }
    
    /**
     * Muda o status do atendimento atual
     * @param mixed $statusAtual (array[int] | int)
     * @param int $novoStatus
     * @param string $campoData
     */
    private function mudaStatusAtualResponse(Context $context, $statusAtual, $novoStatus, $campoData) {
        $usuario = $context->getUser();
        if (!$usuario) {
            $this->app()->gotoHome();
        }
        $response = new JsonResponse();
        $atual = $this->atendimentoAndamento($usuario);
        if ($atual) {
            // atualizando atendimento
            $response->success = $this->mudaStatusAtendimento($atual, $statusAtual, $novoStatus, $campoData);
        }
        if ($response->success) {
            $response->data = $atual->toArray();
        } else {
            $response->message = _('Nenhum atendimento disponível');
        }
        return $response;
    }
    
    /**
     * 
     * @param Atendimento $atendimento
     * @param mixed $statusAtual (array[int] | int)
     * @param int $novoStatus
     * @param string $campoData
     * @return boolean
     */
    private function mudaStatusAtendimento(Atendimento $atendimento, $statusAtual, $novoStatus, $campoData) {
        $cond = '';
        if ($campoData !== null) {
            $cond = ", e.$campoData = :data";
        }
        if (!is_array($statusAtual)) {
            $statusAtual = array($statusAtual);
        }
        // atualizando atendimento
        $query = $this->em()->createQuery("
            UPDATE 
                Novosga\Model\Atendimento e 
            SET 
                e.status = :novoStatus $cond
            WHERE 
                e.id = :id AND 
                e.status IN (:statusAtual)
        ");
        if ($campoData !== null) {
            $query->setParameter('data', DateUtil::nowSQL());
        }
        $query->setParameter('novoStatus', $novoStatus);
        $query->setParameter('id', $atendimento->getId());
        $query->setParameter('statusAtual', $statusAtual);
        return $query->execute() > 0;
    }
    
    /**
     * Inicia o atendimento com o proximo da fila
     * @param Novosga\Context $context
     */
    public function iniciar(Context $context) {
        return $this->mudaStatusAtualResponse($context, AtendimentoBusiness::CHAMADO_PELA_MESA, AtendimentoBusiness::ATENDIMENTO_INICIADO, 'dataInicio');
    }
    
    /**
     * Marca o atendimento como nao compareceu
     * @param Novosga\Context $context
     */
    public function nao_compareceu(Context $context) {
        return $this->mudaStatusAtualResponse($context, AtendimentoBusiness::CHAMADO_PELA_MESA, AtendimentoBusiness::NAO_COMPARECEU, 'dataFim');
    }
    
    /**
     * Marca o atendimento como encerrado
     * @param Novosga\Context $context
     */
    public function encerrar(Context $context) {
        return $this->mudaStatusAtualResponse($context, AtendimentoBusiness::ATENDIMENTO_INICIADO, AtendimentoBusiness::ATENDIMENTO_ENCERRADO, null);
    }
    
    /**
     * Marca o atendimento como encerrado e codificado
     * @param Novosga\Context $context
     */
    public function codificar(Context $context) {
        $response = new JsonResponse(false);
        try {
            if (!$context->request()->isPost()) {
                throw new Exception(_('Somente via POST'));
            }
            $unidade = $context->getUnidade();
            if (!$unidade) {
                throw new Exception(_('Nenhum unidade escolhida'));
            }
            $usuario = $context->getUser();
            $atual = $this->atendimentoAndamento($usuario);
            if (!$atual) {
                throw new Exception(_('Nenhum atendimento em andamento'));
            }
            $servicos = $context->request()->post('servicos');
            $servicos = Arrays::valuesToInt(explode(',', $servicos));
            if (empty($servicos)) {
                throw new Exception(_('Nenhum serviço selecionado'));
            }
            $conn = $this->em()->getConnection();
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO atend_codif (atendimento_id, servico_id, valor_peso) VALUES (:atendimento, :servico, 1)");
            foreach ($servicos as $s) {
                $stmt->bindValue('atendimento', $atual->getId());
                // TODO: verificar se o usuario realmente pode atender o servico informado
                $stmt->bindValue('servico', $s);
                $stmt->execute();
            }
            // verifica se esta encerrando e redirecionando
            $redirecionar = $context->request()->post('redirecionar');
            if ($redirecionar) {
                $servico = $context->request()->post('novoServico');
                $redirecionado = $this->redireciona_atendimento($atual, $servico, $unidade, $usuario);
                if (!$redirecionado) {
                    throw new Exception(sprintf(_('Erro ao redirecionar atendimento %s para o serviço %s'), $atual->getId(), $servico));
                }
            }
            $response->success = $this->mudaStatusAtendimento($atual, AtendimentoBusiness::ATENDIMENTO_ENCERRADO, AtendimentoBusiness::ATENDIMENTO_ENCERRADO_CODIFICADO, 'dataFim');
            if (!$response->success) {
                throw new Exception(sprintf(_('Erro ao codificar o atendimento %s'), $atual->getId()));
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $response->message = $e->getMessage() . '<br><br><br>' . $e->getTraceAsString();
        }
        return $response;
    }
    
    /**
     * Marca o atendimento como erro de triagem. E gera um novo atendimento para
     * o servico informado.
     * @param Novosga\Context $context
     */
    public function redirecionar(Context $context) {
        $unidade = $context->getUnidade();
        $response = new JsonResponse(false);
        try {
            if (!$context->request()->isPost()) {
                throw new Exception(_('Somente via POST'));
            }
            if (!$unidade) {
                throw new Exception(_('Nenhum unidade escolhida'));
            }
            $usuario = $context->getUser();
            $servico = (int) $context->request()->post('servico');
            $atual = $this->atendimentoAndamento($usuario);
            if (!$atual) {
                throw new Exception(_('Nenhum atendimento em andamento'));
            }
            $conn = $this->em()->getConnection();
            $conn->beginTransaction();
            $redirecionado = $this->redireciona_atendimento($atual, $servico, $unidade, $usuario);
            if (!$redirecionado) {
                throw new Exception(sprintf(_('Erro ao redirecionar atendimento %s para o serviço %s'), $atual->getId(), $servico));
            }
            $response->success = $this->mudaStatusAtendimento($atual, array(AtendimentoBusiness::ATENDIMENTO_INICIADO, AtendimentoBusiness::ATENDIMENTO_ENCERRADO), AtendimentoBusiness::ERRO_TRIAGEM, 'dataFim');
            if (!$response->success) {
                throw new Exception(sprintf(_('Erro ao mudar status do atendimento %s para encerrado'), $atual->getId()));
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $response->message = $e->getMessage() . '<br><br><br>' . $e->getTraceAsString();
        }
        return $response;
    }
    
    private function redireciona_atendimento(Atendimento $atendimento, $servico, $unidade, UsuarioSessao $usuario) {
        // copiando a senha do atendimento atual
        $su = $this->em()
                ->createQuery('SELECT e FROM Novosga\Model\ServicoUnidade e WHERE e.servico = :servico AND e.unidade = :unidade')
                ->setParameter('servico', $servico)
                ->setParameter('unidade', $unidade)
                ->getSingleResult();
        ;
        $novo = new Atendimento();
        $novo->setLocal(0);
        $novo->setServicoUnidade($su);
        $novo->setPai($atendimento);
        $novo->setDataChegada(new \DateTime());
        $novo->setStatus(AtendimentoBusiness::SENHA_EMITIDA);
        $novo->setSiglaSenha($atendimento->getSenha()->getSigla());
        $novo->setNumeroSenha($atendimento->getNumeroSenha());
        $novo->setNumeroSenhaServico($atendimento->getNumeroSenhaServico());
        $novo->setUsuario($usuario->getWrapped());
        $novo->setUsuarioTriagem($usuario->getWrapped());
        $novo->setPrioridadeSenha($atendimento->getSenha()->getPrioridade());
        $novo->setNomeCliente($atendimento->getCliente()->getNome());
        $novo->setDocumentoCliente($atendimento->getCliente()->getDocumento());
        $this->em()->persist($novo);
        $this->em()->flush();
        return $novo->getId();
    }
    
    public function info_senha(Context $context) {
        $response = new JsonResponse();
        $unidade = $context->getUser()->getUnidade();
        if ($unidade) {
            $id = (int) $context->request()->get('id');
            $atendimento = $this->atendimentoBusiness->buscaAtendimento($unidade, $id);
            if ($atendimento) {
                $response->data = $atendimento->toArray();
                $response->success = true;
            } else {
                $response->message = _('Atendimento inválido');
            }
        }
        return $response;
    }
    
    /**
     * Busca os atendimentos a partir do número da senha
     * @param Novosga\Context $context
     */
    public function consulta_senha(Context $context) {
        $response = new JsonResponse();
        $unidade = $context->getUser()->getUnidade();
        if ($unidade) {
            $numero = $context->request()->get('numero');
            $atendimentos = $this->atendimentoBusiness->buscaAtendimentos($unidade, $numero);
            $response->data['total'] = sizeof($atendimentos);
            foreach ($atendimentos as $atendimento) {
                $response->data['atendimentos'][] = $atendimento->toArray();
            }
            $response->success = true;
        } else{
            $response->message = _('Nenhuma unidade selecionada');
        }
        return $response;
    }
    
}
