import React, { useState, useEffect } from 'react';
import {
    ModalOverlay,
    ModalContent,
    ModalHeader,
    CloseButton,
    FormGroup,
    Label,
    Textarea,
    Select,
    SubmitButton
} from './FeedbackModal.styles';

interface FeedbackModalProps {
    isVisible: boolean;
    onClose: () => void;
    messageId: number | null;
    conversationId: number | null;
    sessionId: number | null;
    onSubmit: (feedback: FeedbackData) => Promise<void>;
}

export interface FeedbackData {
    message_id: number;
    conversation_id: number;
    session_id: number;
    category: string;
    comment: string;
    rating: string;
}

const FeedbackModal: React.FC<FeedbackModalProps> = ({
    isVisible,
    onClose,
    messageId,
    conversationId,
    sessionId,
    onSubmit
}) => {
    const [category, setCategory] = useState('helpfulness');
    const [comment, setComment] = useState('');
    const [submitting, setSubmitting] = useState(false);

    // Reset form when modal closes
    useEffect(() => {
        if (!isVisible) {
            setCategory('helpfulness');
            setComment('');
            setSubmitting(false);
        }
    }, [isVisible]);

    // Handle ESC key to close modal
    useEffect(() => {
        const handleEsc = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };

        if (isVisible) {
            document.addEventListener('keydown', handleEsc);
        }

        return () => {
            document.removeEventListener('keydown', handleEsc);
        };
    }, [isVisible, onClose]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!messageId || !conversationId || !sessionId) {
            console.error('Missing required IDs for feedback submission');
            return;
        }

        if (!comment.trim()) {
            alert('Please enter your feedback');
            return;
        }

        setSubmitting(true);

        try {
            await onSubmit({
                message_id: messageId,
                conversation_id: conversationId,
                session_id: sessionId,
                category,
                comment: comment.trim(),
                rating: 'good' // Default rating
            });

            onClose();
        } catch (error) {
            console.error('Failed to submit feedback:', error);
        } finally {
            setSubmitting(false);
        }
    };

    const handleOverlayClick = (e: React.MouseEvent) => {
        if (e.target === e.currentTarget) {
            onClose();
        }
    };

    return (
        <ModalOverlay isVisible={isVisible} onClick={handleOverlayClick}>
            <ModalContent onClick={(e) => e.stopPropagation()}>
                <ModalHeader>
                    <h3>📝 Share Your Feedback</h3>
                    <CloseButton onClick={onClose} title="Close">
                        ×
                    </CloseButton>
                </ModalHeader>

                <form onSubmit={handleSubmit}>
                    <FormGroup>
                        <Label htmlFor="category">Category</Label>
                        <Select
                            id="category"
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                        >
                            <option value="helpfulness">Helpfulness</option>
                            <option value="accuracy">Accuracy</option>
                            <option value="speed">Response Speed</option>
                            <option value="tone">Tone & Clarity</option>
                            <option value="relevance">Relevance</option>
                        </Select>
                    </FormGroup>

                    <FormGroup>
                        <Label htmlFor="comment">Your Feedback</Label>
                        <Textarea
                            id="comment"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder="Tell us what you think about this response..."
                            disabled={submitting}
                        />
                    </FormGroup>

                    <SubmitButton type="submit" disabled={submitting}>
                        {submitting ? 'Submitting...' : 'Submit Feedback'}
                    </SubmitButton>
                </form>
            </ModalContent>
        </ModalOverlay>
    );
};

export default FeedbackModal;
