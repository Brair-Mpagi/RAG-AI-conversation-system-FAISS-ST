import styled from 'styled-components'

const QUESTIONS = [
  { icon: '🎓', text: 'What faculties does MMU have?' },
  { icon: '👨‍💼', text: "Who is MMU's current VC?" },
  { icon: '📅', text: 'When does the next intake start?' },
  { icon: '📍', text: 'Where is MMU located?' },
  { icon: '📝', text: 'How do I apply for admission?' },
  { icon: '💰', text: 'What are the tuition fees?' },
]

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
  gap: 10px;
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
`

const Chip = styled.button`
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 12px 14px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  line-height: 1.4;
  transition: all var(--transition);
  box-shadow: var(--shadow-sm);

  .emoji { font-size: 18px; flex-shrink: 0; }

  &:hover {
    border-color: var(--primary);
    background: var(--bg-hover);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    color: var(--primary);
  }

  &:active { transform: translateY(0); }
  &:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

  @media (max-width: 640px) {
    &:nth-child(1),
    &:nth-child(5),
    &:nth-child(6) {
      display: none;
    }
  }
`

interface Props {
  onSelect: (question: string) => void
  disabled?: boolean
}

export default function SuggestedQuestions({ onSelect, disabled }: Props) {
  return (
    <Grid>
      {QUESTIONS.map((q) => (
        <Chip key={q.text} onClick={() => onSelect(q.text)} disabled={disabled}>
          <span className="emoji">{q.icon}</span>
          <span>{q.text}</span>
        </Chip>
      ))}
    </Grid>
  )
}
