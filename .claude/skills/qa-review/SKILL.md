---
name: qa-review
description: Varre a lista Review dos boards Backend e Frontend do Taskero e dispara o QA (subagente qa-tester) em cada task encontrada, registrando evidência como comentário no card. Use quando o usuário pedir para "rodar o QA", "validar o que está em review", "testar as tasks em review" ou algo equivalente no contexto do footfy.
---

Este skill não testa nada você mesmo: ele localiza as tasks na lista **Review** dos dois boards do Taskero (Backend e Frontend) e delega a validação de cada uma ao subagente `qa-tester` (`.claude/agents/qa-tester.md`), que roda os testes, confere o card e comenta a evidência.

## Passos

1. `mcp__taskero__get_boards` para confirmar os IDs atuais dos boards **Backend** e **Frontend** (não assuma IDs fixos — já mudaram antes).
2. Para cada board, `mcp__taskero__get_board_cards` e filtre os cards cuja `list` seja **Review**.
3. Se nenhum board tiver cards em Review, informe isso ao usuário e pare — não invoque o `qa-tester` à toa.
4. Para cada card encontrado, invoque o Agent tool com `subagent_type: qa-tester`, um por card. No prompt, informe explicitamente:
   - O `card_id` e o board (Backend ou Frontend) — o `qa-tester` usa isso para saber qual repositório local checar.
   - Que ele deve seguir seu próprio arquivo de definição à risca (ler critérios do card, isolar em `git worktree`, comentar evidência, mover o card ao final).
   - Se houver mais de um card, pode disparar as chamadas do Agent tool em paralelo (numa única mensagem) — cada `qa-tester` isola seu próprio worktree por FOOT-XXXX, então não há conflito entre eles.
5. Ao receber o resultado de cada `qa-tester`, monte um resumo final para o usuário: uma linha por card com FOOT-XXXX, veredito (✅/❌) e para onde foi movido (Testes ou Em desenvolvimento). Não repita o comentário inteiro que já foi postado no Taskero — ele já está lá, o resumo aqui é só um índice rápido.

## Observações

- Este skill é sob demanda (rodado manualmente pelo usuário), não um cron/automação em background — cada execução varre o estado atual das duas filas de Review no momento em que foi chamado.
- Se um card em Review não tiver as seções padrão do `task-planner` (`Critérios de aceite`, `Cenário de testes (QA)`), o `qa-tester` já sabe lidar com isso (valida de forma mais genérica e avisa) — não tente reescrever o card por conta própria aqui.
