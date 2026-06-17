---
name: strategy-report
description: Produz uma análise estratégica do projeto footfy (roadmap, análise de mercado/concorrência, refatoração de escopo, planejamento de frontend/UX, recomendação de ferramentas) sempre no mesmo formato de relatório. Use quando o usuário pedir algo como "planejar próximas fases", "roadmap", "análise de mercado", "avaliar refatoração de escopo", "planejar uma feature no frontend" ou "que ferramenta usar para X" no contexto do footfy/footfy-frontend.
---

Este skill não faz a análise você mesmo: ele delega para o subagente `project-strategist` (já configurado em `.claude/agents/project-strategist.md`) e garante que o resultado final seja sempre apresentado no mesmo template de relatório, para ficar consistente entre execuções.

## Passos

1. Identifique o tema da análise a partir do pedido do usuário (e dos `args` deste skill, se houver): roadmap, mercado/concorrência, refatoração de escopo, frontend/UX, ou ferramentas.
2. Invoque o Agent tool com `subagent_type: project-strategist`. No prompt enviado ao agente:
   - Inclua o pedido original do usuário, sem reinterpretar ou estreitar o escopo.
   - Lembre o agente de verificar o estado real do repositório (não confiar só no README) antes de concluir, conforme já instruído no seu próprio arquivo de definição.
   - Peça explicitamente que a resposta final seja estruturada nas seções do template abaixo, usando esses títulos exatos.
3. Ao receber o resultado do agente, repasse ao usuário preservando as seções do template. Não resuma a ponto de perder os trade-offs ou riscos levantados.

## Template do relatório

```
## Contexto atual
(o que já existe hoje, com base no estado real do repo — não no README desatualizado)

## Opções avaliadas / Oportunidades
(alternativas consideradas, incluindo achados de pesquisa de mercado quando aplicável)

## Riscos e trade-offs
(o que cada opção custa — esforço, risco técnico, impacto na arquitetura Controller → Service → Repository → Model, impacto na UX)

## Prioridade recomendada
(qual opção/sequência o agente recomenda, e por quê)

## Próximos passos
(ações concretas — sinalizando quais devem ser delegadas a `backend-specialist` ou `git-specialist`, e quais exigem decisão do usuário)
```

## Observações

- Se o pedido for muito pequeno/pontual (ex: uma pergunta de sim/não sobre uma ferramenta específica), não force as cinco seções — peça ao `project-strategist` uma resposta direta e avise que o template completo é mais útil para decisões maiores.
- Este skill não persiste o relatório em arquivo. Se o usuário quiser guardar a análise, confirme onde salvar antes de criar qualquer arquivo.
