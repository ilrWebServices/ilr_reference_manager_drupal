<?php

namespace Drupal\reference_manager\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\reference_manager\PropertyValueAcademicIdentifier;
use RenanBr\BibTexParser\Listener;
use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\Processor\NamesProcessor;
use RenanBr\BibTexParser\Processor\TagNameCaseProcessor;

/**
 * Provides a user upload form for importing references.
 */
class UserUploadForm extends FormBase {

  // See https://antistatique.net/en/blog/autowiring-in-drupal-the-hidden-gem-many-dev-still-ignore
  use AutowireTrait;

  /**
   * Constructs a new UserUploadForm object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reference_manager_user_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['bibtex'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Bibtex'),
      '#description' => $this->t('Paste Bibtex data here. You can include multiple entries.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload File'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  // public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $bibtex = $form_state->getValue('bibtex');

    if (!empty($bibtex)) {
      // $this->messenger()->addStatus($this->t('File "@filename" has been uploaded successfully to temporary storage.', [
      //   '@filename' => $file->getFilename(),
      // ]));

      // // Log the upload.
      // $this->getLogger('reference_manager')->info('File uploaded: @filename (ID: @fid)', [
      //   '@filename' => $file->getFilename(),
      //   '@fid' => $file->id(),
      // ]);

      $this->processUploadedData($bibtex);
    }
  }

  /**
   * Process the uploaded data immediately.
   */
  protected function processUploadedData(string $bibtex) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      if ($bibtex !== FALSE) {
        $listener = new Listener();
        $listener->addProcessor(new TagNameCaseProcessor(CASE_LOWER));
        $listener->addProcessor(new NamesProcessor());

        $parser = new Parser();
        $parser->addListener($listener);

        $parser->parseString($bibtex); // or parseFile('/path/to/file.bib')
        $data = $listener->export();

        return;

        foreach ($data as $publication) {
          if ((string) $publication->PUBLIC_VIEW !== 'Yes') {
            continue;
          }

          if ((string) $publication->CONTYPE === 'Other') {
            continue;
          }

          // Create authors.
          $authors = [];
          foreach ($publication->INTELLCONT_AUTH as $author) {
            $new_author = $node_storage->create([
              'type' => 'refman_schema_person',
              'schema_given_name' => (string) $author->FNAME,
              'schema_family_name' => (string) $author->LNAME,
              'schema_additional_name' => (string) $author->MNAME,
              'uid' => $account->id(),
            ]);

            if ($new_author->save()) {
              $authors[] = $new_author;
            }
          }

          // Create a publisher, but don't set the name (title) or save it, yet.
          /** @var \Drupal\node\NodeInterface $publisher */
          $publisher = $node_storage->create([
            'type' => 'refman_schema_organization',
            'title' => '-',
            'uid' => $account->id(),
          ]);

          // Switch on the contribution type.
          if ((string) $publication->CONTYPE === 'Journal Article') {
            $entity_bundle = 'refman_schema_scholarly_article';
            $publisher->setTitle((string) $publication->JOURNAL->JOURNAL_NAME);
          }
          elseif ((string) $publication->CONTYPE === 'Book, Scholarly' || (string) $publication->CONTYPE === 'Book, Textbook') {
            $entity_bundle = 'refman_schema_book';
            $publisher->setTitle((string) $publication->PUBLISHER);
          }
          elseif ((string) $publication->CONTYPE === 'Book Chapter') {
            $entity_bundle = 'refman_schema_chapter';
            $publisher->setTitle((string) $publication->PUBLISHER);

          }
          elseif ((string) $publication->CONTYPE === 'Newspaper') {
            $entity_bundle = 'refman_schema_news_article';
            $publisher->setTitle((string) $publication->PUBLISHER);

          }
          elseif ((string) $publication->CONTYPE === 'Magazine Publication') {
            $entity_bundle = 'refman_schema_article';
            $publisher->setTitle((string) $publication->PUBLISHER);

          }
          else {
            // Plain old CreativeWork, which is the default.
            $entity_bundle = 'refman_schema_creative_work';
            $publisher->setTitle((string) $publication->PUBLISHER);
          }

          $publication_date = (string) $publication->PUB_START ?: (string) $publication->SUB_START;
          $publish_status = (string) $publication->STATUS === 'Published';

          /** @var \Drupal\node\NodeInterface $entity */
          $entity = $node_storage->create([
            'type' => $entity_bundle,
            'title' => (string) $publication->TITLE[0] ?? '-',
            'created' => strtotime($publication['created']),
            // 'changed' => strtotime($publication['lastModified']), // Why not work?
            'status' => $publish_status,
            'schema_date_published' => $publication_date,
            'uid' => $account->id(),
          ]);

          if ($entity->hasField('schema_pagination') && (string) $publication->PAGENUM) {
            $entity->set('schema_pagination', (string) $publication->PAGENUM);
          }

          // Mainly for simple creative works: Store the type from the import data.
          if ($entity->hasField('schema_additional_type')) {
            $entity->set('schema_additional_type', (string) $publication->CONTYPE);
          }

          if ($entity->hasField('schema_url') && (string) $publication->WEB_ADDRESS) {
            $entity->set('schema_url', ['uri' => (string) $publication->WEB_ADDRESS]);
          }

          // Create issue and volume.
          if ($entity->hasField('schema_is_part_of') && (string) $publication->VOLUME && (string) $publication->ISSUE) {
            /** @var \Drupal\node\NodeInterface $volume */
            $volume = $node_storage->create([
              'type' => 'refman_schema_publication_volume',
              'schema_volume_number' => (string) $publication->VOLUME,
              'uid' => $account->id(),
            ]);
            $volume->save();

            /** @var \Drupal\node\NodeInterface $issue */
            $issue = $node_storage->create([
              'type' => 'refman_schema_publication_issue',
              'schema_issue_number' => (string) $publication->ISSUE,
              'schema_is_part_of' => $volume,
              'uid' => $account->id(),
            ]);
            $issue->save();

            $entity->set('schema_is_part_of', $issue);
          }

          // Save and set the publisher.
          if ($publisher->label()) {
            $publisher->save();
            $entity->set('schema_publisher', $publisher);
          }

          // Set the authors.
          $entity->set('schema_author', $authors);

          // Set any academic identifiers. They are stored as a custom
          // schema.org PropertyValues content type
          if ($entity->hasField('schema_identifier_academic_id')) {
            /** @var PropertyValueAcademicIdentifier[] $academic_identifier_data */
            $academic_identifier_data = [];

            /** @var NodeInterface[] $academic_identifier_nodes */
            $academic_identifier_nodes = [];

            if ((string) $publication->ARXIVNUM) {
              $academic_identifier_data[] = new PropertyValueAcademicIdentifier('arXiv', (string) $publication->ARXIVNUM);
            }

            if ((string) $publication->DOI) {
              // See https://stackoverflow.com/a/48524047.
              preg_match('/10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/', (string) $publication->DOI, $doi_matches);

              if ($doi_matches) {
                $academic_identifier_data[] = new PropertyValueAcademicIdentifier('DOI', $doi_matches[0]);
              }
            }

            if ((string) $publication->PMID) {
              $academic_identifier_data[] = new PropertyValueAcademicIdentifier('PMID', (string) $publication->PMID);
            }

            if ((string) $publication->PMCID) {
              $academic_identifier_data[] = new PropertyValueAcademicIdentifier('PMCID', (string) $publication->PMCID);
            }

            foreach ($academic_identifier_data as $academic_identifier) {
              $new_academic_identifier = $node_storage->create([
                'type' => 'refman_property_value_apid',
                'schema_property_id_academic_id' => strtolower($academic_identifier->propertyId),
                'schema_value_academic_id' => $academic_identifier->value,
                'uid' => $account->id(),
              ]);

              if ($new_academic_identifier->save()) {
                $academic_identifier_nodes[] = $new_academic_identifier;
              }
            }

            if ($academic_identifier_nodes) {
              $entity->set('schema_identifier_academic_id', $academic_identifier_nodes);
            }
          }

          // Save the entity.
          $entity->save();

        } // End publication
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error processing import: @error', [
        '@error' => $e->getMessage(),
      ]));

      $this->getLogger('reference_manager')->error('Error processing import: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
