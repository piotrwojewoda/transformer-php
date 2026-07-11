<?php

declare(strict_types=1);

namespace App\LanguageModel\Infrastructure\Persistence\Doctrine\Repository;

use App\LanguageModel\Domain\Corpus\CorpusId;
use App\LanguageModel\Domain\Repository\VocabularyRepository;
use App\LanguageModel\Domain\Token\Character;
use App\LanguageModel\Domain\Token\Vocabulary;
use App\LanguageModel\Infrastructure\Persistence\Doctrine\Entity\VocabularyEntity;
use Doctrine\ORM\EntityManagerInterface;

// The Doctrine implementation of VocabularyRepository.
final readonly class DoctrineVocabularyRepository implements VocabularyRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Vocabulary $vocabulary): void
    {
        // We need the numeric id of the parent corpus.
        $corpus = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM corpora WHERE uuid = ?',
            [$vocabulary->corpusId->value],
        );
        if ($corpus === false) {
            throw new \RuntimeException("No corpus with uuid {$vocabulary->corpusId->value}.");
        }
        $corpusId = (int) $corpus['id'];

        // Wipe the old vocabulary for this corpus and write the
        // new one. Atomic, in a single transaction.
        $this->em->wrapInTransaction(function () use ($vocabulary, $corpusId) {
            $this->em->getConnection()->executeStatement('DELETE FROM vocabulary WHERE corpus_id = ?', [$corpusId]);
            foreach ($vocabulary->entries() as $entry) {
                $e = new VocabularyEntity();
                $e->corpusId = $corpusId;
                $e->tokenId = $entry['id'];
                $char = $entry['char'];
                // The empty string is reserved for <pad>; replace
                // it with a single NUL byte so the database
                // never sees "" (some DBs complain).
                if (\strlen($char) === 0) {
                    $char = "\x00";
                }
                $e->character = $char;
                $this->em->persist($e);
            }
            $this->em->flush();
        });
    }

    public function findByCorpus(CorpusId $corpusId): ?Vocabulary
    {
        $corpus = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM corpora WHERE uuid = ?',
            [$corpusId->value],
        );
        if ($corpus === false) {
            return null;
        }
        $id = (int) $corpus['id'];
        $entries = $this->em->getRepository(VocabularyEntity::class)->findBy(['corpusId' => $id], ['tokenId' => 'ASC']);
        // If the corpus has no vocabulary yet, return an empty
        // one (with just the three special tokens).
        if ($entries === []) {
            return Vocabulary::empty($corpusId);
        }
        // Walk every saved row and rebuild the idToChar and
        // charToId maps in memory.
        $idToChar = [];
        $charToId = [];
        $maxId = Vocabulary::FIRST_USER_ID - 1;
        foreach ($entries as $e) {
            $char = $e->character;
            $cp = 0;
            if (\strlen($char) > 0) {
                $ord = mb_ord($char, 'UTF-8');
                if ($ord !== false) {
                    $cp = $ord;
                }
            }
            $c = new Character($cp);
            $idToChar[$e->tokenId] = $c;
            $charToId[$cp] = $e->tokenId;
            if ($e->tokenId > $maxId) {
                $maxId = $e->tokenId;
            }
        }
        // The next id is one more than the biggest id we've seen.
        $nextId = $maxId + 1;
        if ($nextId < Vocabulary::FIRST_USER_ID) {
            $nextId = Vocabulary::FIRST_USER_ID;
        }

        return new Vocabulary($corpusId, $idToChar, $charToId, $nextId);
    }
}
